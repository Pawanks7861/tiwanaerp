<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BbsBarCountRounding;
use App\Enums\Boq\BbsCountMode;
use App\Enums\Boq\BbsLapMode;
use App\Enums\Boq\BbsShapeCode;
use App\Enums\Boq\BbsStatus;
use App\Enums\Boq\CircleConstantMode;
use App\Enums\Boq\CostCalculationBasis;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\CostRateSource;
use App\Enums\Boq\MeasurementFormulaType;
use App\Enums\Boq\MeasurementSheetStatus;
use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\RateComparisonGstMode;
use App\Enums\Boq\SteelUnitWeightMethod;
use App\Exports\BbsExport;
use App\Exports\BbsTemplateExport;
use App\Exports\MaterialNormTemplateExport;
use App\Http\Controllers\Boq\Concerns\RequiresAdvancedBoqCalculations;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMeasurementSheet;
use App\Models\Boq\CostingRateLibraryEntry;
use App\Models\Boq\MaterialRecipeTemplate;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Vendor;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Services\Boq\AdvancedBoqRateRefreshService;
use App\Services\Boq\BbsImportService;
use App\Services\Boq\BbsService;
use App\Services\Boq\CostBuildUpService;
use App\Services\Boq\EstimatedActualCostService;
use App\Services\Boq\MaterialNormService;
use App\Services\Boq\MaterialRecipeService;
use App\Services\Boq\MaterialRequirementService;
use App\Services\Boq\MeasurementSheetService;
use App\Services\Boq\PlanningBoqMappingService;
use App\Services\Boq\RateComparisonService;
use App\Support\Boq\BoqCompanySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoqItemWorkspaceController extends Controller
{
    use RequiresAdvancedBoqCalculations;

    public function __construct(
        private readonly MeasurementSheetService $measurements,
        private readonly CostBuildUpService $costs,
        private readonly MaterialNormService $norms,
        private readonly MaterialRequirementService $requirements,
        private readonly BbsService $bbs,
        private readonly BbsImportService $bbsImport,
        private readonly EstimatedActualCostService $costVariance,
        private readonly MaterialRecipeService $recipes,
        private readonly RateComparisonService $rateComparisons,
        private readonly PlanningBoqMappingService $planningMaps,
        private readonly AdvancedBoqRateRefreshService $rateRefresh,
    ) {}

    public function show(Request $request, Project $project, Boq $boq, BoqItem $item): Response
    {
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);

        $user = $request->user();
        $advanced = BoqCompanySettings::enabled();
        $withCosts = $user->can('boq.view_costs');
        $manageCost = $withCosts && $user->can('boq.manage_cost');
        $viewMats = $user->can('boq.view_material_requirements');
        $manageMats = $viewMats && $user->can('boq.manage_material_norms');
        $viewBbs = $user->can('boq.view_bbs');
        $manageBbs = $viewBbs && $user->can('boq.manage_bbs');
        $approveBbs = $user->can('boq.approve_bbs');
        $sheet = $this->measurements->ensureSheet($item, $user);
        $sheet->load(['lines.unit:id,symbol,name', 'drawingRevision:id,revision_code,status', 'approver:id,name']);

        $item->load(['unit:id,symbol,name', 'section:id,code,name']);

        $includeCost = $advanced && $withCosts && $request->boolean('cost');
        $includeMats = $advanced && $viewMats && $request->boolean('materials');
        $includeProc = $viewMats && $request->boolean('procurement');
        $includeBbs = $advanced && $viewBbs && $request->boolean('bbs');
        $includeRateCompare = $advanced && $withCosts && $request->boolean('rate_comparison');
        $includePlanningMap = $advanced && $request->boolean('planning_map');
        $bbsPayload = $includeBbs ? $this->bbs->workspacePayload($item) : null;
        $bbsModel = $includeBbs && ($bbsPayload['bbs']['id'] ?? null)
            ? BoqBbs::query()->find($bbsPayload['bbs']['id'])
            : null;

        return Inertia::render('Boq/ItemWorkspace', [
            'project' => ProjectHeader::for($project),
            'boq' => BoqPresenter::header($boq, $withCosts),
            'item' => $this->itemPayload($item, $withCosts),
            'measurement' => $this->sheetPayload($sheet),
            'location_breakdown' => $this->measurements->locationBreakdown($sheet),
            'formula_types' => MeasurementFormulaType::options(),
            'circle_constant_modes' => CircleConstantMode::options(),
            'quantity_sources' => QuantitySource::options(),
            'statuses' => MeasurementSheetStatus::options(),
            'drawing_revisions' => DrawingRevision::query()
                ->whereHas('drawing', fn ($q) => $q->where('project_id', $project->id))
                ->with('drawing:id,drawing_number,title')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (DrawingRevision $r) => [
                    'id' => $r->id,
                    'label' => trim(($r->drawing?->drawing_number ?? '').' Rev '.$r->revision_code.' ('.$r->status->value.')'),
                    'status' => $r->status->value,
                ])->all(),
            'cost_build_up' => $includeCost ? $this->costs->workspacePayload($item) : null,
            'cost_variance' => $includeCost ? $this->costVariance->itemAnalysis($item) : null,
            'cost_component_types' => $withCosts ? CostComponentType::options() : [],
            'cost_rate_sources' => $withCosts ? CostRateSource::options() : [],
            'cost_calculation_bases' => $withCosts ? CostCalculationBasis::options() : [],
            'cost_masters' => $includeCost ? $this->costMasters() : null,
            'material_norms' => $includeMats ? $this->norms->workspacePayload($item) : null,
            'material_options' => ($includeMats || $includeBbs) ? $this->materialOptions() : [],
            'item_procurement' => $includeProc ? $this->requirements->itemProcurement($item) : null,
            'bbs_workspace' => $bbsPayload,
            'rate_comparison' => $includeRateCompare ? $this->rateComparisons->workspacePayload($item) : null,
            'rate_comparison_gst_modes' => $withCosts ? RateComparisonGstMode::options() : [],
            'vendor_options' => $includeRateCompare ? $this->vendorOptions() : [],
            'planning_map' => $includePlanningMap ? $this->planningMaps->workspacePayload($item) : null,
            'planning_task_options' => $includePlanningMap ? $this->planningTaskOptions($project) : [],
            'planning_mapping_methods' => $advanced ? PlanningBoqMappingMethod::options() : [],
            'advanced_boq_calculations' => $advanced,
            'can' => [
                'edit' => $user->can('update', $boq) && $boq->isEditable() && $sheet->isEditable(),
                'submit' => $user->can('update', $boq) && $boq->isEditable() && $sheet->isEditable(),
                'approve' => $user->can('boq.approve') && $boq->isEditable() && $sheet->status === MeasurementSheetStatus::Submitted,
                'reject' => $user->can('boq.approve') && $boq->isEditable() && $sheet->status === MeasurementSheetStatus::Submitted,
                'set_source' => $user->can('update', $boq) && $boq->isEditable(),
                'view_costs' => $withCosts,
                'manage_cost' => $manageCost && $boq->isEditable(),
                'view_material_requirements' => $viewMats,
                'manage_material_norms' => $manageMats && $boq->isEditable(),
                'view_bbs' => $viewBbs,
                'manage_bbs' => $manageBbs && $boq->isEditable() && ($bbsModel?->isEditable() ?? true),
                'enable_bbs' => $manageBbs && $boq->isEditable(),
                'submit_bbs' => $manageBbs && $boq->isEditable() && $bbsModel?->isEditable(),
                'approve_bbs' => $approveBbs && $boq->isEditable() && $bbsModel?->status === BbsStatus::Submitted
                    && (config('approvals.allow_self_approval') || (int) $bbsModel->submitted_by !== (int) $user->id),
                'reject_bbs' => $approveBbs && $boq->isEditable() && $bbsModel?->status === BbsStatus::Submitted
                    && (config('approvals.allow_self_approval') || (int) $bbsModel->submitted_by !== (int) $user->id),
                'map_bbs_steel' => $manageBbs && $boq->isEditable(),
                'manage_bbs_adjustments' => $user->can('boq.manage_bbs_adjustments') && $manageBbs && $boq->isEditable(),
                'manage_planning_map' => $user->can('update', $boq) && $boq->isEditable(),
            ],
        ]);
    }

    public function saveMeasurements(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'drawing_revision_id' => ['nullable', 'integer'],
            'circle_constant_mode' => ['nullable', Rule::enum(CircleConstantMode::class)],
            'circle_constant_value' => ['nullable', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.location' => ['nullable', 'string', 'max:160'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.formula_type' => ['required', Rule::enum(MeasurementFormulaType::class)],
            'lines.*.nos' => ['nullable', 'numeric'],
            'lines.*.length' => ['nullable', 'numeric'],
            'lines.*.width' => ['nullable', 'numeric'],
            'lines.*.height_depth' => ['nullable', 'numeric'],
            'lines.*.diameter' => ['nullable', 'numeric'],
            'lines.*.radius' => ['nullable', 'numeric'],
            'lines.*.manual_quantity' => ['nullable', 'numeric'],
            'lines.*.addition_quantity' => ['nullable', 'numeric'],
            'lines.*.deduction_quantity' => ['nullable', 'numeric'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.drawing_revision_id' => ['nullable', 'integer'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $sheet = $this->measurements->ensureSheet($item, $request->user());
        $this->measurements->saveLines(
            $sheet,
            $data['lines'],
            $request->user(),
            $data['drawing_revision_id'] ?? null,
            $data['title'] ?? null,
            $data['circle_constant_mode'] ?? null,
            isset($data['circle_constant_value']) ? (string) $data['circle_constant_value'] : null,
        );

        return back()->with('success', 'Measurement sheet saved. Quantities were recalculated on the server.');
    }

    public function submit(Request $request, Project $project, Boq $boq, BoqItem $item, BoqMeasurementSheet $sheet): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertSheetScope($project, $boq, $item, $sheet);
        Gate::authorize('update', $boq);
        $this->measurements->submit($sheet, $request->user());

        return back()->with('success', 'Measurement sheet submitted for approval.');
    }

    public function approve(Request $request, Project $project, Boq $boq, BoqItem $item, BoqMeasurementSheet $sheet): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertSheetScope($project, $boq, $item, $sheet);
        abort_unless($request->user()->can('boq.approve'), 403);
        $this->measurements->approve($sheet, $request->user());

        return back()->with('success', 'Measurement sheet approved. BOQ quantity updated when source is Measurement.');
    }

    public function reject(Request $request, Project $project, Boq $boq, BoqItem $item, BoqMeasurementSheet $sheet): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertSheetScope($project, $boq, $item, $sheet);
        abort_unless($request->user()->can('boq.approve'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->measurements->reject($sheet, $request->user(), $data['reason']);

        return back()->with('success', 'Measurement sheet rejected.');
    }

    public function setQuantitySource(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->assertScope($project, $boq, $item);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'quantity_source' => ['required', Rule::enum(QuantitySource::class)],
            'takeoff_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->measurements->setQuantitySource(
            $item,
            QuantitySource::from($data['quantity_source']),
            $data['takeoff_reference'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Quantity source updated.');
    }

    public function saveCostBuildUp(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);
        abort_unless($request->user()->can('boq.manage_cost'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'components' => ['present', 'array', 'max:200'],
            'components.*.id' => ['nullable', 'integer'],
            'components.*.component_type' => ['required', Rule::enum(CostComponentType::class)],
            'components.*.source_id' => ['nullable', 'integer'],
            'components.*.description' => ['nullable', 'string', 'max:255'],
            'components.*.consumption_qty' => ['required', 'numeric', 'min:0'],
            'components.*.consumption_unit' => ['nullable', 'string', 'max:40'],
            'components.*.rate' => ['required', 'numeric', 'min:0'],
            'components.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'components.*.rate_source' => ['nullable', Rule::enum(CostRateSource::class)],
            'components.*.calculation_basis' => ['nullable', Rule::enum(CostCalculationBasis::class)],
            'components.*.base_rate' => ['nullable', 'numeric', 'min:0'],
            'components.*.factor' => ['nullable', 'numeric', 'gt:0'],
            'components.*.factor_reference' => ['nullable', 'string', 'max:120'],
            'components.*.percent_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'components.*.amount_per_boq_unit' => ['nullable', 'numeric', 'min:0'],
            'components.*.rate_library_id' => ['nullable', 'integer'],
        ]);

        $this->costs->saveComponents($item, $data['components'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'cost' => 1])
            ->with('success', 'Cost build-up saved. Cost rate recalculated on the server.');
    }

    public function saveRateComparison(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);
        abort_unless($request->user()->can('boq.manage_cost'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'quotes' => ['present', 'array', 'max:100'],
            'quotes.*.id' => ['nullable', 'integer'],
            'quotes.*.vendor_id' => ['nullable', 'integer'],
            'quotes.*.vendor_name' => ['nullable', 'string', 'max:160'],
            'quotes.*.quoted_rate' => ['required', 'numeric', 'min:0'],
            'quotes.*.gst_mode' => ['required', Rule::enum(RateComparisonGstMode::class)],
            'quotes.*.gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quotes.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rateComparisons->saveQuotes($item, $data['quotes'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'rate_comparison' => 1])
            ->with('success', 'Rate comparison saved. Variances recalculated on the server. No vendor was awarded.');
    }

    public function savePlanningMap(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'maps' => ['present', 'array', 'max:100'],
            'maps.*.id' => ['nullable', 'integer'],
            'maps.*.project_task_id' => ['required', 'integer'],
            'maps.*.mapping_method' => ['required', Rule::enum(PlanningBoqMappingMethod::class)],
            'maps.*.allocated_boq_qty' => ['required', 'numeric', 'min:0'],
            'maps.*.boq_unit_symbol' => ['nullable', 'string', 'max:40'],
            'maps.*.planning_qty' => ['required', 'numeric', 'min:0'],
            'maps.*.planning_unit_symbol' => ['nullable', 'string', 'max:40'],
            'maps.*.conversion_factor' => ['nullable', 'numeric', 'min:0'],
            'maps.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->planningMaps->saveMaps($item, $data['maps'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'planning_map' => 1])
            ->with('success', 'Planning mapping saved. Conversion factors calculated on the server.');
    }

    public function suggestCostRate(Request $request, Project $project, Boq $boq, BoqItem $item): JsonResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);
        abort_unless($request->user()->can('boq.manage_cost'), 403);

        $data = $request->validate([
            'component_type' => ['required', Rule::enum(CostComponentType::class)],
            'rate_source' => ['required', Rule::enum(CostRateSource::class)],
            'source_id' => ['nullable', 'integer'],
        ]);

        $suggestion = $this->costs->suggestRate(
            $boq,
            CostComponentType::from($data['component_type']),
            CostRateSource::from($data['rate_source']),
            isset($data['source_id']) ? (int) $data['source_id'] : null,
        );

        return response()->json($suggestion);
    }

    public function previewRateRefresh(Request $request, Project $project, Boq $boq, BoqItem $item): JsonResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);
        abort_unless($request->user()->can('boq.manage_cost'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'scope' => ['required', Rule::in(['material', 'labour'])],
        ]);

        return response()->json($this->rateRefresh->preview($item, $data['scope']));
    }

    public function applyRateRefresh(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);
        abort_unless($request->user()->can('boq.manage_cost'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'scope' => ['required', Rule::in(['material', 'labour'])],
            'confirm' => ['accepted'],
        ]);

        $result = $this->rateRefresh->apply($item, $data['scope'], $request->user());
        $count = count($result['changes']);

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'cost' => 1])
            ->with('success', $count > 0
                ? "Refreshed {$count} {$data['scope']} rate(s) from current masters."
                : 'No rate changes to apply.');
    }

    public function saveMaterialNorms(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);
        abort_unless($request->user()->can('boq.manage_material_norms'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'norms' => ['present', 'array', 'max:200'],
            'norms.*.id' => ['nullable', 'integer'],
            'norms.*.material_id' => ['required', 'integer'],
            'norms.*.consumption_qty' => ['required', 'numeric', 'min:0'],
            'norms.*.consumption_unit' => ['required', 'string', 'max:40'],
            'norms.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'norms.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->norms->saveNorms($item, $data['norms'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'materials' => 1])
            ->with('success', 'Material norms saved. Requirements recalculated on the server.');
    }

    public function importNormsFromCost(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);
        abort_unless($request->user()->can('boq.manage_material_norms'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'cost_component_ids' => ['required', 'array', 'min:1'],
            'cost_component_ids.*' => ['integer'],
        ]);

        $this->norms->importFromCostBuildUp($item, $data['cost_component_ids'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'materials' => 1])
            ->with('success', 'Material norms created from Cost Build-Up (base consumption; wastage not double-applied).');
    }

    public function applyRecipe(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);
        abort_unless($request->user()->can('boq.manage_material_norms'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'recipe_id' => ['required', 'integer'],
        ]);

        $recipe = MaterialRecipeTemplate::query()->whereKey($data['recipe_id'])->firstOrFail();
        $this->recipes->applyToBoqItem($item, $recipe, $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'materials' => 1])
            ->with('success', 'Active recipe applied to material norms.');
    }

    public function enableBbs(Request $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_bbs'), 403);
        abort_unless($request->user()->can('boq.manage_bbs'), 403);
        Gate::authorize('update', $boq);

        $this->bbs->enable($item, $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'bbs' => 1])
            ->with('success', 'BBS enabled for this BOQ item.');
    }

    public function saveBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_bbs'), 403);
        abort_unless($request->user()->can('boq.manage_bbs'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'drawing_revision_id' => ['nullable', 'integer'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_weight_method' => ['nullable', Rule::enum(SteelUnitWeightMethod::class)],
            'steel_profile_id' => ['nullable', 'integer'],
            'bar_count_rounding' => ['nullable', Rule::enum(BbsBarCountRounding::class)],
            'lines' => ['present', 'array', 'max:2000'],
            'lines.*.structure_location' => ['nullable', 'string', 'max:160'],
            'lines.*.bar_mark' => ['required', 'string', 'max:40'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.diameter_mm' => ['required', 'integer', 'min:1', 'max:64'],
            'lines.*.number_of_members' => ['required', 'integer', 'min:0'],
            'lines.*.bars_per_member' => ['required', 'integer', 'min:0'],
            'lines.*.count_mode' => ['nullable', Rule::enum(BbsCountMode::class)],
            'lines.*.distribution_length' => ['nullable', 'numeric', 'min:0'],
            'lines.*.start_cover' => ['nullable', 'numeric', 'min:0'],
            'lines.*.end_cover' => ['nullable', 'numeric', 'min:0'],
            'lines.*.spacing' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.include_end_bar' => ['nullable', 'boolean'],
            'lines.*.faces_layers_multiplier' => ['nullable', 'integer', 'min:1'],
            'lines.*.bar_count_rounding' => ['nullable', Rule::enum(BbsBarCountRounding::class)],
            'lines.*.shape_code' => ['required', Rule::enum(BbsShapeCode::class)],
            'lines.*.a_length' => ['nullable', 'numeric'],
            'lines.*.b_length' => ['nullable', 'numeric'],
            'lines.*.c_length' => ['nullable', 'numeric'],
            'lines.*.d_length' => ['nullable', 'numeric'],
            'lines.*.e_length' => ['nullable', 'numeric'],
            'lines.*.hook_length' => ['nullable', 'numeric', 'min:0'],
            'lines.*.bend_deduction' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lap_mode' => ['nullable', Rule::enum(BbsLapMode::class)],
            'lines.*.lap_diameter_factor' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lap_length' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lap_count_per_bar' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
            'maps' => ['nullable', 'array'],
            'maps.*.diameter_mm' => ['required', 'integer', 'min:1'],
            'maps.*.material_id' => ['nullable', 'integer'],
            'adjustments' => ['nullable', 'array', 'max:100'],
            'adjustments.*.diameter_mm' => ['nullable', 'integer', 'min:1', 'max:64'],
            'adjustments.*.material_id' => ['nullable', 'integer'],
            'adjustments.*.adjustment_qty_kg' => ['required_with:adjustments', 'numeric'],
            'adjustments.*.reason' => ['required_with:adjustments', 'string', 'max:500'],
            'adjustments.*.reference' => ['nullable', 'string', 'max:160'],
        ]);

        $this->bbs->save($bbs, $data, $data['lines'], $data['maps'] ?? [], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'bbs' => 1])
            ->with('success', 'BBS saved. Cutting lengths and weights recalculated on the server.');
    }

    public function saveBbsMaps(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_bbs'), 403);
        abort_unless($request->user()->can('boq.manage_bbs'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'maps' => ['present', 'array'],
            'maps.*.diameter_mm' => ['required', 'integer', 'min:1'],
            'maps.*.material_id' => ['nullable', 'integer'],
        ]);

        $this->bbs->saveMaps($bbs, $data['maps'], $request->user());

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'bbs' => 1])
            ->with('success', 'Steel material mapping saved.');
    }

    public function submitBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        abort_unless($request->user()->can('boq.manage_bbs'), 403);
        Gate::authorize('update', $boq);
        $this->bbs->submit($bbs, $request->user());

        return back()->with('success', 'BBS submitted for approval.');
    }

    public function approveBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        abort_unless($request->user()->can('boq.approve_bbs'), 403);
        $this->bbs->approve($bbs, $request->user());

        return back()->with('success', 'BBS approved. Steel requirement now follows BBS (planning only — no stock/ledger/Tally).');
    }

    public function rejectBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        abort_unless($request->user()->can('boq.approve_bbs'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->bbs->reject($bbs, $request->user(), $data['reason']);

        return back()->with('success', 'BBS rejected.');
    }

    public function exportBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): BinaryFileResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        abort_unless($request->user()->can('boq.view_bbs'), 403);

        $name = 'bbs-'.$item->item_code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new BbsExport($bbs), $name);
    }

    public function bbsTemplate(Request $request, Project $project, Boq $boq, BoqItem $item): BinaryFileResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        abort_unless($request->user()->can('boq.view_bbs'), 403);

        return Excel::download(new BbsTemplateExport, 'bbs-import-template.xlsx');
    }

    public function materialNormsTemplate(Request $request, Project $project, Boq $boq, BoqItem $item): BinaryFileResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertScope($project, $boq, $item);
        abort_unless($request->user()->can('boq.view_material_requirements') || $request->user()->can('boq.manage_material_norms'), 403);

        return Excel::download(new MaterialNormTemplateExport, 'material-norm-import-template.xlsx');
    }

    public function importBbs(Request $request, Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertBbsScope($project, $boq, $item, $bbs);
        abort_unless($request->user()->can('boq.manage_bbs'), 403);
        Gate::authorize('update', $boq);

        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $result = $this->bbsImport->import($bbs, $data['file'], $request->user(), $this->bbs);

        if ($result['errors'] !== []) {
            return back()->withErrors([
                'bbs_import' => collect($result['errors'])->map(fn ($e) => 'Row '.$e['row'].': '.$e['message'])->implode(' | '),
            ]);
        }

        return redirect()
            ->route('projects.boqs.items.workspace', [$project, $boq, $item, 'bbs' => 1])
            ->with('success', "Imported {$result['imported']} BBS line(s). Derived weights recalculated on the server.");
    }

    private function assertBbsScope(Project $project, Boq $boq, BoqItem $item, BoqBbs $bbs): void
    {
        $this->assertScope($project, $boq, $item);
        abort_unless((int) $bbs->boq_item_id === (int) $item->id, 404);
        abort_unless((int) $bbs->project_id === (int) $project->id, 404);
        abort_unless((int) $bbs->company_id === (int) $project->company_id, 404);
    }

    private function assertScope(Project $project, Boq $boq, BoqItem $item): void
    {
        abort_unless((int) $boq->project_id === (int) $project->id, 404);
        abort_unless((int) $item->boq_id === (int) $boq->id, 404);
    }

    private function assertSheetScope(Project $project, Boq $boq, BoqItem $item, BoqMeasurementSheet $sheet): void
    {
        $this->assertScope($project, $boq, $item);
        abort_unless((int) $sheet->boq_item_id === (int) $item->id, 404);
        abort_unless((int) $sheet->project_id === (int) $project->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(BoqItem $item, bool $withCosts): array
    {
        $payload = [
            'id' => $item->id,
            'line_uid' => $item->line_uid,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'description' => $item->description,
            'hsn_sac' => $item->hsn_sac,
            'unit' => $item->unit?->only(['id', 'symbol', 'name']),
            'section' => $item->section?->only(['id', 'code', 'name']),
            'quantity' => (string) $item->quantity,
            'quantity_source' => $item->quantity_source?->value ?? QuantitySource::Direct->value,
            'takeoff_reference' => $item->takeoff_reference,
            'bbs_applicable' => (bool) $item->bbs_applicable,
            'client_rate' => (string) $item->client_rate,
            'client_amount' => (string) $item->client_amount,
        ];

        if ($withCosts) {
            $payload['cost_rate'] = (string) $item->cost_rate;
            $payload['cost_amount'] = (string) $item->cost_amount;
            $payload['margin_percent'] = (string) $item->margin_percent;
            $payload['selling_rate'] = (string) $item->selling_rate;
            $payload['material_rate'] = (string) $item->material_rate;
            $payload['labour_rate'] = (string) $item->labour_rate;
            $payload['equipment_rate'] = (string) $item->equipment_rate;
            $payload['subcontract_rate'] = (string) $item->subcontract_rate;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialOptions(): array
    {
        return Material::query()->active()->with('unit:id,symbol')->orderBy('name')->limit(2000)
            ->get(['id', 'code', 'name', 'unit_id', 'standard_rate', 'item_type'])
            ->map(fn (Material $m) => [
                'value' => $m->id,
                'label' => trim("{$m->code} {$m->name}"),
                'unit' => $m->unit?->symbol,
                'rate' => (string) ($m->standard_rate ?? '0'),
                'item_type' => $m->item_type?->value ?? null,
            ])->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function vendorOptions(): array
    {
        return Vendor::query()->active()->orderBy('name')->limit(2000)
            ->get(['id', 'code', 'name'])
            ->map(fn (Vendor $v) => [
                'value' => $v->id,
                'label' => trim(($v->code ? $v->code.' — ' : '').$v->name),
            ])->all();
    }

    /**
     * @return list<array{value: int, label: string, planned_qty: string, unit: string|null}>
     */
    private function planningTaskOptions(Project $project): array
    {
        return ProjectTask::query()
            ->where('project_id', $project->id)
            ->whereNotNull('parent_id')
            ->with('unit:id,symbol')
            ->orderBy('wbs_code')
            ->orderBy('name')
            ->limit(2000)
            ->get(['id', 'name', 'wbs_code', 'planned_qty', 'unit_id', 'parent_id'])
            ->map(fn (ProjectTask $t) => [
                'value' => $t->id,
                'label' => trim(($t->wbs_code ? $t->wbs_code.' — ' : '').$t->name),
                'planned_qty' => (string) ($t->planned_qty ?? '0'),
                'unit' => $t->unit?->symbol,
            ])->all();
    }

    /**
     * @return array{materials: list<array<string, mixed>>, labour_trades: list<array<string, mixed>>, equipment_types: list<array<string, mixed>>, rate_library: list<array<string, mixed>>}
     */
    private function costMasters(): array
    {
        return [
            'materials' => $this->materialOptions(),
            'rate_library' => CostingRateLibraryEntry::query()
                ->where('is_active', true)
                ->orderBy('entry_type')
                ->orderBy('description')
                ->limit(500)
                ->get(['id', 'entry_type', 'description', 'unit', 'rate'])
                ->map(fn (CostingRateLibraryEntry $e) => [
                    'value' => $e->id,
                    'label' => trim("[{$e->entry_type}] {$e->description}"),
                    'unit' => $e->unit,
                    'rate' => (string) $e->rate,
                    'entry_type' => $e->entry_type,
                ])->all(),
            'labour_trades' => LabourTrade::query()->active()->orderBy('name')
                ->get(['id', 'name', 'default_daily_wage'])
                ->map(fn (LabourTrade $t) => [
                    'value' => $t->id,
                    'label' => $t->name,
                    'unit' => 'day',
                    'rate' => (string) ($t->default_daily_wage ?? '0'),
                ])->all(),
            'equipment_types' => EquipmentType::query()->active()->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (EquipmentType $e) => [
                    'value' => $e->id,
                    'label' => $e->name,
                    'unit' => 'hour',
                    'rate' => '0',
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetPayload(BoqMeasurementSheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'title' => $sheet->title,
            'description' => $sheet->description,
            'status' => $sheet->status->value,
            'status_label' => $sheet->status->label(),
            'total_quantity' => (string) $sheet->total_quantity,
            'drawing_revision_id' => $sheet->drawing_revision_id,
            'circle_constant_mode' => $sheet->circle_constant_mode instanceof CircleConstantMode
                ? $sheet->circle_constant_mode->value
                : (string) ($sheet->circle_constant_mode ?? CircleConstantMode::MathematicalPi->value),
            'circle_constant_value' => $sheet->circle_constant_value !== null ? (string) $sheet->circle_constant_value : null,
            'calculation_profile_snapshot' => $sheet->calculation_profile_snapshot,
            'approved_at' => optional($sheet->approved_at)?->toIso8601String(),
            'approver' => $sheet->approver?->only(['id', 'name']),
            'rejection_reason' => $sheet->rejection_reason,
            'lines' => $sheet->lines->map(fn ($line) => [
                'id' => $line->id,
                'location' => $line->location,
                'description' => $line->description,
                'formula_type' => $line->formula_type->value,
                'nos' => $line->nos !== null ? (string) $line->nos : null,
                'length' => $line->length !== null ? (string) $line->length : null,
                'width' => $line->width !== null ? (string) $line->width : null,
                'height_depth' => $line->height_depth !== null ? (string) $line->height_depth : null,
                'diameter' => $line->diameter !== null ? (string) $line->diameter : null,
                'radius' => $line->radius !== null ? (string) $line->radius : null,
                'manual_quantity' => $line->manual_quantity !== null ? (string) $line->manual_quantity : null,
                'addition_quantity' => (string) $line->addition_quantity,
                'deduction_quantity' => (string) $line->deduction_quantity,
                'calculated_base' => (string) $line->calculated_base,
                'calculated_quantity' => (string) $line->calculated_quantity,
                'unit_id' => $line->unit_id,
                'drawing_revision_id' => $line->drawing_revision_id,
                'remarks' => $line->remarks,
                'sort_order' => $line->sort_order,
            ])->all(),
        ];
    }
}

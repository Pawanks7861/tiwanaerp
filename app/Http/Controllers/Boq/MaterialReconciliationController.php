<?php

namespace App\Http\Controllers\Boq;

use App\Exports\MaterialReconciliationExport;
use App\Exports\SteelReconciliationExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Projects\Project;
use App\Services\Boq\MaterialReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaterialReconciliationController extends Controller
{
    public function __construct(private readonly MaterialReconciliationService $reconciliation) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);

        $filters = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'boq_head' => ['nullable', 'string', 'max:80'],
            'requirement_source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->reconciliation->materialSummary($project, $filters);
        $canCosts = $request->user()->can('boq.view_costs');

        $rows = array_map(function (array $row) use ($canCosts) {
            if (! $canCosts) {
                unset($row['estimated_variance_value']);
            }

            return $row;
        }, $result['rows']);

        return Inertia::render('Boq/MaterialReconciliation', [
            'project' => ProjectHeader::for($project),
            'rows' => $rows,
            'totals' => $result['totals'],
            'filters' => $filters,
            'formula_notes' => $result['formula_notes'],
            'materials' => Material::query()->active()->orderBy('name')->limit(2000)
                ->get(['id', 'code', 'name'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}")])
                ->all(),
            'categories' => MaterialCategory::query()->orderBy('name')->limit(500)
                ->get(['id', 'name'])
                ->map(fn (MaterialCategory $c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            'status_options' => $this->statusOptions(),
            'source_options' => [
                ['value' => null, 'label' => '— All —'],
                ['value' => 'Material Norm', 'label' => 'Material Norm'],
                ['value' => 'BBS', 'label' => 'BBS'],
            ],
            'can' => [
                'export' => true,
                'view_costs' => $canCosts,
                'view_cost_report' => $canCosts && $request->user()->can('reports.view') && $request->user()->can('reports.view_financial'),
            ],
        ]);
    }

    public function show(Request $request, Project $project, Material $material): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);
        abort_unless((int) $material->company_id === (int) $project->company_id, 404);

        $unit = $request->string('unit')->toString() ?: null;
        $detail = $this->reconciliation->materialDetail($project, $material->id, $unit);
        $canCosts = $request->user()->can('boq.view_costs');

        if ($detail['summary'] && ! $canCosts) {
            unset($detail['summary']['estimated_variance_value']);
        }

        return Inertia::render('Boq/MaterialReconciliationShow', [
            'project' => ProjectHeader::for($project),
            'material' => [
                'id' => $material->id,
                'code' => $material->code,
                'name' => $material->name,
            ],
            'detail' => $detail,
            'unit' => $unit,
            'can' => [
                'view_costs' => $canCosts,
                'view_cost_report' => $canCosts && $request->user()->can('reports.view') && $request->user()->can('reports.view_financial'),
            ],
        ]);
    }

    public function sources(Request $request, Project $project, Material $material): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);
        abort_unless((int) $material->company_id === (int) $project->company_id, 404);

        $data = $request->validate([
            'type' => ['required', 'in:mr,po,grn,issue,return'],
        ]);

        $documents = $this->reconciliation->sourceDocuments($project, $material->id, $data['type']);

        return Inertia::render('Boq/MaterialReconciliationSources', [
            'project' => ProjectHeader::for($project),
            'material' => [
                'id' => $material->id,
                'code' => $material->code,
                'name' => $material->name,
            ],
            'type' => $data['type'],
            'documents' => $documents,
        ]);
    }

    public function export(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);

        $filters = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'boq_head' => ['nullable', 'string', 'max:80'],
            'requirement_source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->reconciliation->materialSummary($project, $filters);
        $name = 'material-reconciliation-'.$project->code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new MaterialReconciliationExport($project, $result['rows'], $filters),
            $name,
        );
    }

    public function steelIndex(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);

        $filters = $request->validate([
            'diameter_mm' => ['nullable', 'integer'],
            'material_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'bbs_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $this->reconciliation->steelSummary($project, $filters);

        return Inertia::render('Boq/SteelReconciliation', [
            'project' => ProjectHeader::for($project),
            'rows' => $result['rows'],
            'filters' => $filters,
            'formula_notes' => $result['formula_notes'],
            'materials' => Material::query()->active()->orderBy('name')->limit(2000)
                ->get(['id', 'code', 'name'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}")])
                ->all(),
            'status_options' => $this->statusOptions(),
            'can' => [
                'export' => true,
                'view_cost_report' => $request->user()->can('boq.view_costs')
                    && $request->user()->can('reports.view')
                    && $request->user()->can('reports.view_financial'),
            ],
        ]);
    }

    public function steelShow(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);

        $data = $request->validate([
            'diameter_mm' => ['required', 'integer', 'min:1'],
            'material_id' => ['required', 'integer'],
        ]);

        $detail = $this->reconciliation->steelDiameterDetail(
            $project,
            (int) $data['diameter_mm'],
            (int) $data['material_id'],
        );

        return Inertia::render('Boq/SteelReconciliationShow', [
            'project' => ProjectHeader::for($project),
            'detail' => $detail,
            'diameter_mm' => (int) $data['diameter_mm'],
            'material_id' => (int) $data['material_id'],
        ]);
    }

    public function steelExport(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation'), 403);

        $filters = $request->validate([
            'diameter_mm' => ['nullable', 'integer'],
            'material_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'bbs_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $this->reconciliation->steelSummary($project, $filters);
        $name = 'steel-reconciliation-'.$project->code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new SteelReconciliationExport($project, $result['rows'], $filters),
            $name,
        );
    }

    /**
     * @return list<array{value: string|null, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => null, 'label' => '— All —'],
            ['value' => 'Within Plan', 'label' => 'Within Plan'],
            ['value' => 'Over Issued', 'label' => 'Over Issued'],
            ['value' => 'Under Issued', 'label' => 'Under Issued'],
            ['value' => 'Procurement Pending', 'label' => 'Procurement Pending'],
            ['value' => 'No Activity', 'label' => 'No Activity'],
        ];
    }
}

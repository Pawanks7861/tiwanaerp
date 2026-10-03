<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\RateAnalysisStatus;
use App\Enums\Boq\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Boq\RateAnalysisRequest;
use App\Models\Boq\RateAnalysis;
use App\Models\Boq\RateAnalysisItem;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Services\Boq\RateAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RateAnalysisController extends Controller
{
    public function __construct(private readonly RateAnalysisService $analyses) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [RateAnalysis::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RateAnalysisStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = $project->rateAnalyses()
            ->with('unit:id,symbol')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->each->setRelation('project', $project);

        return Inertia::render('RateAnalysis/Index', [
            'project' => ProjectHeader::for($project),
            'analyses' => $rows->map(fn (RateAnalysis $ra) => [
                ...$ra->only(['id', 'code', 'name', 'output_quantity', 'total_cost', 'unit_rate']),
                'unit' => $ra->unit?->symbol,
                'status' => $ra->status->value,
                'status_label' => $ra->status->label(),
                'can' => [
                    'update' => $ra->status === RateAnalysisStatus::Draft && $user->can('update', $ra),
                    'delete' => $ra->status === RateAnalysisStatus::Draft && $user->can('delete', $ra),
                    'approve' => $ra->status === RateAnalysisStatus::Draft && $user->can('approve', $ra),
                ],
            ])->all(),
            'filters' => $filters,
            'statuses' => RateAnalysisStatus::options(),
            'can' => ['create' => $user->can('create', [RateAnalysis::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [RateAnalysis::class, $project]);

        return $this->form($project, null);
    }

    public function store(RateAnalysisRequest $request, Project $project): RedirectResponse
    {
        $analysis = $this->analyses->save($project, $request->validated());

        return redirect()->route('projects.rate-analyses.show', [$project, $analysis])
            ->with('success', "Rate analysis {$analysis->code} created.");
    }

    public function show(Project $project, RateAnalysis $rateAnalysis): Response
    {
        Gate::authorize('view', $rateAnalysis);

        return $this->form($project, $rateAnalysis);
    }

    public function update(RateAnalysisRequest $request, Project $project, RateAnalysis $rateAnalysis): RedirectResponse
    {
        $this->analyses->save($project, $request->validated(), $rateAnalysis);

        return back()->with('success', 'Rate analysis saved.');
    }

    public function destroy(Project $project, RateAnalysis $rateAnalysis): RedirectResponse
    {
        Gate::authorize('delete', $rateAnalysis);

        $this->analyses->delete($rateAnalysis);

        return redirect()->route('projects.rate-analyses.index', $project)->with('success', 'Rate analysis deleted.');
    }

    public function approve(Request $request, Project $project, RateAnalysis $rateAnalysis): RedirectResponse
    {
        Gate::authorize('approve', $rateAnalysis);

        $this->analyses->approve($rateAnalysis, $request->user());

        return back()->with('success', 'Rate analysis approved.');
    }

    private function form(Project $project, ?RateAnalysis $analysis): Response
    {
        $user = request()->user();
        $analysis?->load('approver:id,name');
        $draft = $analysis?->status === RateAnalysisStatus::Draft;

        return Inertia::render('RateAnalysis/Form', [
            'project' => ProjectHeader::for($project),
            'analysis' => $analysis ? [
                ...$analysis->only([
                    'id', 'code', 'name', 'description', 'unit_id', 'output_quantity', 'overhead_percent', 'profit_percent',
                    ...RateAnalysis::COST_FIELDS, 'unit_rate',
                ]),
                'status' => $analysis->status->value,
                'status_label' => $analysis->status->label(),
                'approved_by' => $analysis->approver?->name,
                'approved_at' => $analysis->approved_at?->toIso8601String(),
                'items' => $analysis->items()->get()->map(fn (RateAnalysisItem $i) => [
                    ...$i->only(['id', 'material_id', 'labour_trade_id', 'equipment_type_id', 'description', 'unit_id', 'quantity', 'wastage_percent', 'rate', 'amount']),
                    'resource_type' => $i->resource_type->value,
                ])->all(),
            ] : null,
            'units' => Unit::query()->active()->orderBy('symbol')->get(['id', 'symbol', 'name'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol])->all(),
            'materials' => Material::query()->active()->orderBy('name')->limit(2000)->get(['id', 'code', 'name', 'unit_id', 'standard_rate'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}"), 'unit_id' => $m->unit_id, 'rate' => $m->standard_rate])->all(),
            'labourTrades' => LabourTrade::query()->active()->orderBy('name')->get(['id', 'name', 'default_daily_wage'])
                ->map(fn (LabourTrade $t) => ['value' => $t->id, 'label' => $t->name, 'rate' => $t->default_daily_wage])->all(),
            'equipmentTypes' => EquipmentType::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (EquipmentType $e) => ['value' => $e->id, 'label' => $e->name])->all(),
            'resourceTypes' => ResourceType::options(),
            'can' => [
                'update' => $analysis ? $draft && $user->can('update', $analysis) : $user->can('create', [RateAnalysis::class, $project]),
                'delete' => $draft && $user->can('delete', $analysis),
                'approve' => $draft && $user->can('approve', $analysis),
            ],
        ]);
    }
}

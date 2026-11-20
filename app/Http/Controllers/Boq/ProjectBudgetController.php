<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\BudgetType;
use App\Enums\CostHead;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Services\Boq\BoqBudgetMaterialSyncService;
use App\Services\Boq\ProjectBudgetService;
use App\Services\Projects\ProjectCostHeadService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectBudgetController extends Controller
{
    public function __construct(
        private readonly ProjectBudgetService $budgets,
        private readonly BoqBudgetMaterialSyncService $materialSync,
        private readonly ProjectCostHeadService $costHeads,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectBudget::class, $project]);

        $user = $request->user();
        $type = BudgetType::tryFrom((string) $request->query('budget_type', BudgetType::MainProject->value))
            ?? BudgetType::MainProject;

        $versions = $project->budgets()
            ->ofType($type)
            ->with(['approver:id,name', 'boq:id,boq_number,version'])
            ->orderByDesc('version')
            ->get()
            ->each->setRelation('project', $project);

        $selectedId = (int) $request->query('version_id', 0);
        $budget = $versions->firstWhere('id', $selectedId) ?? $versions->first();

        $lines = $budget?->lines()->with('boqItem:id,item_code,name')->orderBy('id')->get() ?? collect();
        $byHead = [];
        foreach (CostHead::cases() as $head) {
            $byHead[] = [
                'value' => $head->value,
                'label' => $head->label(),
                'amount' => Decimal::sum($lines->where('cost_head', $head)->pluck('amount'))->toMoney(),
            ];
        }

        $wbsHeads = $this->costHeads->ensureDefaults($project->company)->map(function ($head) use ($lines) {
            $line = $lines->firstWhere('project_cost_head_id', $head->id);

            return [
                'id' => $head->id,
                'code' => $head->code,
                'name' => $head->name,
                'line_id' => $line?->id,
                'amount' => $line ? (string) $line->amount : '0.00',
            ];
        })->values()->all();

        $currentBoqs = $project->boqs()->where('status', BoqStatus::Approved)->where('is_current', true)
            ->orderBy('boq_number')->get(['id', 'boq_number', 'version', 'title', 'total_cost_amount']);

        $materialLines = $lines->filter(fn (ProjectBudgetLine $l) => $l->source_type === BudgetLineSource::BoqMaterial)->values();
        $labourLines = $lines->filter(fn (ProjectBudgetLine $l) => $l->source_type === BudgetLineSource::BoqLabour)->values();
        $equipmentLines = $lines->filter(fn (ProjectBudgetLine $l) => $l->source_type === BudgetLineSource::BoqEquipment)->values();
        $boqOtherLines = $lines->filter(fn (ProjectBudgetLine $l) => $l->source_type === BudgetLineSource::BoqOther)->values();
        $otherLines = $lines->reject(
            fn (ProjectBudgetLine $l) => in_array($l->source_type?->value, BudgetLineSource::syncOwnedValues(), true)
        )->values();

        $pendingRevision = $project->budgets()
            ->boqEstimate()
            ->where('status', BudgetStatus::Approved)
            ->where('materials_sync_pending', true)
            ->exists();

        $mainApproved = $this->budgets->approvedOfType($project, BudgetType::MainProject);
        $boqApproved = $this->budgets->approvedOfType($project, BudgetType::BoqEstimate);

        $currentEstimateId = $boqApproved?->id;

        return Inertia::render('Budget/Index', [
            'project' => ProjectHeader::for($project),
            'budget_type' => $type->value,
            'budget_type_label' => $type->label(),
            'budget_types' => BudgetType::options(),
            'main_project_budget_total' => $mainApproved?->total_amount,
            'boq_estimate_total' => $boqApproved?->total_amount,
            'versions' => $versions->map(function (ProjectBudget $b) use ($currentEstimateId) {
                $isEstimate = $b->budget_type === BudgetType::BoqEstimate;
                $isCurrent = $isEstimate
                    ? ($currentEstimateId !== null && (int) $b->id === (int) $currentEstimateId)
                    : $b->status === BudgetStatus::Approved;

                return [
                    ...$b->only(['id', 'version', 'total_amount', 'boq_id', 'materials_sync_pending']),
                    'budget_type' => $b->budget_type->value,
                    'status' => $b->status->value,
                    'status_label' => $b->status->label(),
                    'is_current' => $isCurrent,
                    'source' => $b->source->value,
                    'source_label' => $b->source->label(),
                    'approved_by' => $b->approver?->name,
                    'approved_at' => $b->approved_at?->toIso8601String(),
                    'materials_synced_at' => $b->materials_synced_at?->toIso8601String(),
                    'boq_label' => $b->boq ? "{$b->boq->boq_number} / R{$b->boq->version}" : null,
                    'source_revision' => $b->boq ? 'R'.$b->boq->version : null,
                ];
            })->all(),
            'budget' => $budget ? [
                ...$budget->only(['id', 'version', 'total_amount', 'boq_id']),
                'budget_type' => $budget->budget_type->value,
                'status' => $budget->status->value,
                'status_label' => $budget->status->label(),
                'editable' => $budget->budget_type === BudgetType::MainProject && $budget->status === BudgetStatus::Draft,
                // Main Project Budget only — BOQ Estimate has no separate approve action.
                'can_approve' => $budget->budget_type === BudgetType::MainProject
                    && $budget->status === BudgetStatus::Draft
                    && $user->can('approve', $budget),
                'is_current' => $budget->budget_type === BudgetType::BoqEstimate
                    ? ($currentEstimateId !== null && (int) $budget->id === (int) $currentEstimateId)
                    : $budget->status === BudgetStatus::Approved,
                'materials_synced_at' => $budget->materials_synced_at?->toIso8601String(),
                'materials_sync_pending' => (bool) $budget->materials_sync_pending,
                'boq_label' => $budget->boq
                    ? "{$budget->boq->boq_number} / R{$budget->boq->version}"
                    : null,
                'source_boq_id' => $budget->boq_id,
                'source_revision' => $budget->boq ? 'R'.$budget->boq->version : null,
                'approved_by' => $budget->approver?->name,
                'approved_at' => $budget->approved_at?->toIso8601String(),
            ] : null,
            'wbsHeads' => $wbsHeads,
            'heads' => $byHead,
            'lines' => $otherLines->map(fn (ProjectBudgetLine $l) => $this->linePayload($l))->all(),
            'materialLines' => $materialLines->map(fn (ProjectBudgetLine $l) => $this->materialLinePayload($l))->all(),
            'labourLines' => $labourLines->map(fn (ProjectBudgetLine $l) => $this->labourLinePayload($l))->all(),
            'equipmentLines' => $equipmentLines->map(fn (ProjectBudgetLine $l) => $this->labourLinePayload($l))->all(),
            'boqOtherLines' => $boqOtherLines->map(fn (ProjectBudgetLine $l) => $this->labourLinePayload($l))->all(),
            'pendingRevision' => $pendingRevision,
            'sync_status' => $this->materialSync->syncStatus(
                $project,
                $budget?->boq_id ? $project->boqs()->find($budget->boq_id) : null
            ),
            'boqs' => $currentBoqs->map(fn (Boq $b) => [
                'value' => $b->id,
                'label' => "{$b->boq_number} v{$b->version} - {$b->title}",
                'total_cost_amount' => $b->total_cost_amount,
            ])->all(),
            'costHeads' => CostHead::options(),
            'can' => [
                'update' => $user->can('update', [ProjectBudget::class, $project]),
            ],
        ]);
    }

    public function createMain(Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $budget = $this->budgets->createInitialBudget($project, []);

        return redirect()->route('projects.budget.index', [
            $project,
            'budget_type' => BudgetType::MainProject->value,
            'version_id' => $budget->id,
        ])->with('success', 'Main Project Budget draft created with WBS heads. Enter allocations, then Approve.');
    }

    public function saveWbsAllocations(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $rawAllocations = $request->input('wbs_allocations', []);
        if (is_array($rawAllocations)) {
            foreach ($rawAllocations as $headId => $amount) {
                if (is_int($amount) || is_float($amount)) {
                    $rawAllocations[$headId] = is_float($amount)
                        ? rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.')
                        : (string) $amount;
                }
            }
            $request->merge(['wbs_allocations' => $rawAllocations]);
        }

        $data = $request->validate([
            'version_id' => ['nullable', 'integer'],
            'wbs_allocations' => ['required', 'array'],
            'wbs_allocations.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
        ]);

        $budgetQuery = $project->budgets()->mainProject();
        $budget = ! empty($data['version_id'])
            ? $budgetQuery->whereKey((int) $data['version_id'])->firstOrFail()
            : $budgetQuery->where('status', BudgetStatus::Draft)->orderByDesc('version')->firstOrFail();

        $amounts = [];
        foreach ($data['wbs_allocations'] as $headId => $amount) {
            $amounts[$headId] = $amount === null || $amount === '' ? '0' : (string) $amount;
        }

        $budget = $this->budgets->saveWbsAllocations($budget, $amounts);

        return redirect()->route('projects.budget.index', [
            $project,
            'budget_type' => BudgetType::MainProject->value,
            'version_id' => $budget->id,
        ])->with('success', 'Main Project Budget WBS allocations saved.');
    }

    public function newVersion(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $type = BudgetType::tryFrom((string) $request->input('budget_type', $request->query('budget_type', BudgetType::MainProject->value)))
            ?? BudgetType::MainProject;
        $budget = $this->budgets->newVersion($project, $type);

        return redirect()->route('projects.budget.index', [
            $project,
            'budget_type' => $type->value,
            'version_id' => $budget->id,
        ])->with('success', "Draft {$type->label()} v{$budget->version} ready.");
    }

    public function storeLine(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $budget = $this->budgets->saveLine($project, $this->lineData($request));

        return redirect()->route('projects.budget.index', [
            $project,
            'budget_type' => $budget->budget_type->value,
            'version_id' => $budget->id,
        ])->with('success', 'Main Project Budget line added.');
    }

    public function updateLine(Request $request, Project $project, ProjectBudgetLine $budgetLine): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $this->budgets->saveLine($project, $this->lineData($request), $budgetLine);

        return back()->with('success', 'Budget line updated.');
    }

    public function destroyLine(Project $project, ProjectBudgetLine $budgetLine): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $this->budgets->deleteLine($budgetLine);

        return back()->with('success', 'Budget line deleted.');
    }

    public function approve(Request $request, Project $project, ProjectBudget $budget): RedirectResponse
    {
        if ($budget->budget_type === BudgetType::BoqEstimate) {
            abort(403, 'BOQ Estimate inherits approval from the finally approved BOQ and cannot be approved separately.');
        }

        Gate::authorize('approve', $budget);

        $this->budgets->approve($budget, $request->user());

        return back()->with('success', "{$budget->budget_type->label()} v{$budget->version} approved.");
    }

    /**
     * @return array{cost_head: string, description: string, amount: string, budget_type?: string}
     */
    private function lineData(Request $request): array
    {
        $amount = $request->input('amount');
        if (is_int($amount) || is_float($amount)) {
            $request->merge(['amount' => is_float($amount) ? rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.') : (string) $amount]);
        }

        return $request->validate([
            'cost_head' => ['required', Rule::enum(CostHead::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'budget_type' => ['sometimes', Rule::enum(BudgetType::class)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(ProjectBudgetLine $l): array
    {
        return [
            ...$l->only(['id', 'description', 'amount', 'boq_item_id']),
            'cost_head' => $l->cost_head->value,
            'source_type' => $l->source_type?->value,
            'boq_item' => $l->boqItem ? trim(($l->boqItem->item_code ?? '').' '.$l->boqItem->name) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function materialLinePayload(ProjectBudgetLine $l): array
    {
        return [
            'id' => $l->id,
            'material_code' => $l->material_code,
            'material_name' => $l->material_name,
            'description' => $l->description,
            'unit' => $l->unit,
            'quantity' => $l->quantity !== null ? (string) $l->quantity : null,
            'rate' => $l->rate !== null ? (string) $l->rate : null,
            'amount' => (string) $l->amount,
            'source_count' => is_array($l->sources) ? count($l->sources) : 0,
            'sources' => $l->sources ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function labourLinePayload(ProjectBudgetLine $l): array
    {
        return [
            'id' => $l->id,
            'description' => $l->description,
            'unit' => $l->unit,
            'quantity' => $l->quantity !== null ? (string) $l->quantity : null,
            'rate' => $l->rate !== null ? (string) $l->rate : null,
            'amount' => (string) $l->amount,
            'boq_item_id' => $l->boq_item_id,
            'source_count' => is_array($l->sources) ? count($l->sources) : 0,
            'sources' => $l->sources ?? [],
        ];
    }
}

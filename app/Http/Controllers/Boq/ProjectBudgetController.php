<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\CostHead;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Services\Boq\BoqBudgetMaterialSyncService;
use App\Services\Boq\ProjectBudgetService;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
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
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectBudget::class, $project]);

        $user = $request->user();
        $versions = $project->budgets()->with(['approver:id,name', 'boq:id,boq_number,version'])->orderByDesc('version')->get()
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
            ->where('status', BudgetStatus::Approved)
            ->where('materials_sync_pending', true)
            ->exists();

        return Inertia::render('Budget/Index', [
            'project' => ProjectHeader::for($project),
            'versions' => $versions->map(fn (ProjectBudget $b) => [
                ...$b->only(['id', 'version', 'total_amount', 'boq_id', 'materials_sync_pending']),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'source' => $b->source->value,
                'source_label' => $b->source->label(),
                'approved_by' => $b->approver?->name,
                'approved_at' => $b->approved_at?->toIso8601String(),
                'materials_synced_at' => $b->materials_synced_at?->toIso8601String(),
                'boq_label' => $b->boq ? "{$b->boq->boq_number} v{$b->boq->version}" : null,
            ])->all(),
            'budget' => $budget ? [
                ...$budget->only(['id', 'version', 'total_amount', 'boq_id']),
                'status' => $budget->status->value,
                'editable' => $budget->status === BudgetStatus::Draft,
                'can_approve' => $budget->status === BudgetStatus::Draft && $user->can('approve', $budget),
                'materials_synced_at' => $budget->materials_synced_at?->toIso8601String(),
                'materials_sync_pending' => (bool) $budget->materials_sync_pending,
                'boq_label' => $budget->boq
                    ? "{$budget->boq->boq_number} v{$budget->boq->version}"
                    : ($versions->firstWhere('id', $budget->id)?->boq_label),
            ] : null,
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

    public function generate(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $data = $request->validate(['boq_id' => ['required', 'integer']]);
        $boq = $project->boqs()->findOrFail($data['boq_id']);
        $budget = $this->budgets->generateFromBoq($project, $boq);

        return redirect()->route('projects.budget.index', [$project, 'version_id' => $budget->id])
            ->with('success', "Budget v{$budget->version} generated from {$boq->boq_number} v{$boq->version}.");
    }

    public function syncPreview(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $data = $request->validate(['boq_id' => ['nullable', 'integer']]);
        $draft = $project->budgets()->where('status', BudgetStatus::Draft)->orderByDesc('version')->first();
        $boqId = $data['boq_id'] ?? $draft?->boq_id
            ?? $project->boqs()->where('status', BoqStatus::Approved)->where('is_current', true)->value('id');
        $boq = $project->boqs()->findOrFail($boqId);

        return response()->json($this->materialSync->preview($project, $boq, $draft));
    }

    public function syncMaterials(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $data = $request->validate([
            'boq_id' => ['nullable', 'integer'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $draft = $project->budgets()->where('status', BudgetStatus::Draft)->orderByDesc('version')->first();
        $boqId = $data['boq_id'] ?? $draft?->boq_id;
        if ($boqId === null) {
            $boqId = $project->boqs()->where('status', BoqStatus::Approved)->where('is_current', true)->value('id');
        }

        $boq = $project->boqs()->findOrFail($boqId);

        if ($draft === null) {
            $approved = $project->budgets()->where('status', BudgetStatus::Approved)->first();
            if ($approved !== null) {
                $approved->forceFill(['materials_sync_pending' => true])->save();

                return redirect()->route('projects.budget.index', [$project, 'version_id' => $approved->id])
                    ->with('error', 'Approved budget cannot be modified. Create a new budget version, then Sync from BOQ.');
            }
        }

        $preview = $this->materialSync->preview($project, $boq, $draft);
        if (! ($data['confirm'] ?? false) && ($preview['creates'] + $preview['updates'] + $preview['removes']) > 0) {
            // Soft confirm via flash for non-AJAX callers; UI should pass confirm=1 after preview.
        }

        $budget = $this->materialSync->syncDraftFromBoq($project, $boq);

        return redirect()->route('projects.budget.index', [$project, 'version_id' => $budget->id])
            ->with('success', "Budget synced from {$boq->boq_number} v{$boq->version} (Material A + Labour B + BBS/equipment).");
    }

    public function newVersion(Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $budget = $this->budgets->newVersion($project);

        return redirect()->route('projects.budget.index', [$project, 'version_id' => $budget->id])
            ->with('success', "Draft budget v{$budget->version} ready.");
    }

    public function storeLine(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', [ProjectBudget::class, $project]);

        $budget = $this->budgets->saveLine($project, $this->lineData($request));

        return redirect()->route('projects.budget.index', [$project, 'version_id' => $budget->id])->with('success', 'Budget line added.');
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
        Gate::authorize('approve', $budget);

        $this->budgets->approve($budget, $request->user());

        return back()->with('success', "Budget v{$budget->version} approved.");
    }

    /**
     * @return array{cost_head: string, description: string, amount: string}
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

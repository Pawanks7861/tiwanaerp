<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\CostHead;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Services\Boq\ProjectBudgetService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectBudgetController extends Controller
{
    public function __construct(private readonly ProjectBudgetService $budgets) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectBudget::class, $project]);

        $user = $request->user();
        $versions = $project->budgets()->with('approver:id,name')->orderByDesc('version')->get()
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

        return Inertia::render('Budget/Index', [
            'project' => ProjectHeader::for($project),
            'versions' => $versions->map(fn (ProjectBudget $b) => [
                ...$b->only(['id', 'version', 'total_amount', 'boq_id']),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'source' => $b->source->value,
                'source_label' => $b->source->label(),
                'approved_by' => $b->approver?->name,
                'approved_at' => $b->approved_at?->toIso8601String(),
            ])->all(),
            'budget' => $budget ? [
                ...$budget->only(['id', 'version', 'total_amount']),
                'status' => $budget->status->value,
                'editable' => $budget->status->value === 'draft',
                'can_approve' => $budget->status->value === 'draft' && $user->can('approve', $budget),
            ] : null,
            'heads' => $byHead,
            'lines' => $lines->map(fn (ProjectBudgetLine $l) => [
                ...$l->only(['id', 'description', 'amount', 'boq_item_id']),
                'cost_head' => $l->cost_head->value,
                'boq_item' => $l->boqItem ? trim(($l->boqItem->item_code ?? '').' '.$l->boqItem->name) : null,
            ])->all(),
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
}

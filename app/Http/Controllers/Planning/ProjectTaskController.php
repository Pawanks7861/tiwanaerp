<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Planning\DependencyType;
use App\Enums\Planning\TaskPriority;
use App\Enums\Planning\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Planning\ProjectTaskRequest;
use App\Models\Boq\BoqItem;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Services\Planning\PlanningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTaskController extends Controller
{
    public function __construct(private readonly PlanningService $planning) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        $user = $request->user();
        $withCosts = $user->can('boq.view_costs');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'assigned_to' => ['nullable', 'integer'],
            'milestone_id' => ['nullable', 'integer'],
        ]);

        $tasks = $project->tasks()
            ->with([
                'assignee:id,name',
                'unit:id,symbol',
                'milestone:id,name',
                'boqItem:id,boq_id,item_code,name,quantity,unit_id',
                'boqItem.unit:id,symbol',
                'dependencies.predecessor:id,wbs_code,name',
            ])
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('wbs_code', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, $id) => $q->where('assigned_to', $id))
            ->when($filters['milestone_id'] ?? null, fn (Builder $q, $id) => $q->where('milestone_id', $id))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(3000)
            ->get();

        return Inertia::render('Planning/Tasks', [
            'project' => ProjectHeader::for($project),
            'tasks' => $tasks->map(fn (ProjectTask $t) => $this->present($t, $withCosts))->all(),
            'filtered' => array_filter($filters) !== [],
            'filters' => $filters,
            'options' => $this->options($project, $withCosts),
            'can' => [
                'create' => $user->can('create', [ProjectTask::class, $project]),
                'update' => $user->can('planning.update'),
                'delete' => $user->can('planning.delete'),
                'update_progress' => $user->can('planning.update_progress'),
                'view_costs' => $withCosts,
            ],
        ]);
    }

    public function suggestWbs(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        $parentId = $request->integer('parent_id') ?: null;
        $parent = $parentId ? $project->tasks()->findOrFail($parentId) : null;

        return response()->json(['wbs_code' => $this->planning->suggestWbs($project, $parent)]);
    }

    public function store(ProjectTaskRequest $request, Project $project): RedirectResponse
    {
        $task = $this->planning->saveTask($project, $request->taskData($request->user()));

        return back()->with('success', "Task {$task->wbs_code} created.");
    }

    public function update(ProjectTaskRequest $request, Project $project, ProjectTask $task): RedirectResponse
    {
        $this->planning->saveTask($project, $request->taskData($request->user()), $task);

        return back()->with('success', 'Task updated.');
    }

    public function updateStatus(Request $request, Project $project, ProjectTask $task): RedirectResponse
    {
        Gate::authorize('updateProgress', $task);

        $data = $request->validate(['status' => ['required', Rule::enum(TaskStatus::class)]]);
        $this->planning->changeStatus($task, TaskStatus::from($data['status']));

        return back()->with('success', 'Task status updated.');
    }

    public function destroy(Project $project, ProjectTask $task): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $this->planning->deleteTask($task);

        return back()->with('success', 'Task deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProjectTask $t, bool $withCosts): array
    {
        $data = [
            ...$t->only([
                'id', 'parent_id', 'wbs_code', 'name', 'description', 'assigned_to', 'milestone_id', 'boq_item_id',
                'duration_days', 'unit_id', 'planned_qty', 'completed_qty', 'progress_percent', 'sort_order',
            ]),
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'priority' => $t->priority->value,
            'planned_start' => $t->planned_start?->toDateString(),
            'planned_finish' => $t->planned_finish?->toDateString(),
            'actual_start' => $t->actual_start?->toDateString(),
            'actual_finish' => $t->actual_finish?->toDateString(),
            'assignee' => $t->assignee?->name,
            'unit' => $t->unit?->symbol,
            'milestone' => $t->milestone?->name,
            'boq_item' => $t->boqItem ? [
                'id' => $t->boqItem->id,
                'item_code' => $t->boqItem->item_code,
                'name' => $t->boqItem->name,
                'quantity' => $t->boqItem->quantity,
                'unit' => $t->boqItem->unit?->symbol,
            ] : null,
            'predecessors' => $t->dependencies->map(fn (TaskDependency $d) => [
                'id' => $d->id,
                'predecessor_id' => $d->predecessor_id,
                'wbs_code' => $d->predecessor?->wbs_code,
                'name' => $d->predecessor?->name,
                'type' => $d->type->value,
                'lag_days' => $d->lag_days,
            ])->values()->all(),
        ];

        if ($withCosts) {
            $data['budget_amount'] = $t->budget_amount;
            $data['actual_cost'] = $t->actual_cost;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Project $project, bool $withCosts): array
    {
        return [
            'members' => ProjectUser::query()
                ->where('project_id', $project->id)
                ->where('is_active', true)
                ->with('user:id,name')
                ->get()
                ->filter(fn (ProjectUser $m) => $m->user !== null)
                ->map(fn (ProjectUser $m) => ['value' => $m->user_id, 'label' => $m->user->name])
                ->sortBy('label')->values()->all(),
            'milestones' => $project->milestones()->orderBy('sort_order')->orderBy('due_date')->get(['id', 'name'])
                ->map(fn (ProjectMilestone $m) => ['value' => $m->id, 'label' => $m->name])->all(),
            'units' => Unit::query()->active()->orderBy('symbol')->get(['id', 'symbol'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol])->all(),
            'boqItems' => $this->planning->linkableBoqItems($project)
                ->with(['unit:id,symbol', 'boq:id,boq_number,version'])
                ->orderBy('boq_id')->orderBy('sort_order')->orderBy('id')
                ->limit(3000)
                ->get(['id', 'boq_id', 'item_code', 'name', 'quantity', 'unit_id'])
                ->map(fn (BoqItem $i) => [
                    'value' => $i->id,
                    'label' => trim(($i->item_code ? $i->item_code.' ' : '').$i->name),
                    'description' => "{$i->quantity} {$i->unit?->symbol} · {$i->boq?->boq_number} v{$i->boq?->version}",
                    'unit_id' => $i->unit_id,
                    'quantity' => $i->quantity,
                ])->all(),
            'statuses' => TaskStatus::options(),
            'priorities' => TaskPriority::options(),
            'dependencyTypes' => DependencyType::options(),
            'transitions' => collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $s) => [
                $s->value => array_map(fn (TaskStatus $t) => $t->value, $s->allowedTransitions()),
            ])->all(),
            'view_costs' => $withCosts,
        ];
    }
}

<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Enums\Boq\PlanningTrackingBasis;
use App\Enums\Planning\DependencyType;
use App\Enums\Planning\TaskPriority;
use App\Enums\Planning\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Planning\ProjectTaskRequest;
use App\Models\Boq\BoqPlanningMap;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Services\Boq\PlanningBoqMappingService;
use App\Services\Planning\PlanningHeadProgressService;
use App\Services\Planning\PlanningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTaskController extends Controller
{
    public function __construct(
        private readonly PlanningService $planning,
        private readonly PlanningBoqMappingService $boqMaps,
    ) {}

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
            'head_id' => ['nullable', 'integer'],
            'on_hold' => ['nullable', 'boolean'],
            'delayed' => ['nullable', 'boolean'],
        ]);

        $today = now()->toDateString();
        $tasks = $project->tasks()
            ->with([
                'assignee:id,name',
                'unit:id,symbol',
                'milestone:id,name',
                'parent:id,wbs_code,name',
                'dependencies.predecessor:id,wbs_code,name',
            ])
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('wbs_code', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, $id) => $q->where('assigned_to', $id))
            ->when($filters['milestone_id'] ?? null, fn (Builder $q, $id) => $q->where('milestone_id', $id))
            ->when($filters['head_id'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                ->where('id', $id)->orWhere('parent_id', $id)))
            ->when($request->boolean('on_hold'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('status', TaskStatus::OnHold)
                ->orWhere('hold_quantity', '>', 0)))
            ->when($request->boolean('delayed'), function (Builder $q) use ($today) {
                $q->where(function (Builder $w) use ($today) {
                    $w->where('status', TaskStatus::Delayed)
                        ->orWhere(fn (Builder $inner) => $inner
                            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::OnHold])
                            ->whereNotNull('planned_finish')
                            ->whereDate('planned_finish', '<', $today));
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(3000)
            ->get();

        $childrenByParent = $tasks->groupBy('parent_id');
        $mapsByTask = BoqPlanningMap::query()
            ->whereIn('project_task_id', $tasks->modelKeys())
            ->where('is_active', true)
            ->with(['item:id,name,item_code,quantity,measurement_nos,unit_id', 'item.unit:id,symbol', 'boq:id,boq_number,version'])
            ->orderByDesc('is_primary')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy('project_task_id');
        $presented = $tasks->map(function (ProjectTask $t) use ($withCosts, $childrenByParent, $mapsByTask) {
            if ($t->isPlanningHead()) {
                $t->setRelation('children', $childrenByParent->get($t->id, collect())->values());
            }

            return $this->present($t, $withCosts, $mapsByTask->get($t->id, collect()));
        })->all();

        return Inertia::render('Planning/Tasks', [
            'project' => ProjectHeader::for($project),
            'tasks' => $presented,
            'summary' => $this->summary($tasks),
            'filtered' => array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false) !== [],
            'filters' => $filters,
            'options' => $this->options($project, $withCosts),
            'can' => [
                'create' => $user->can('create', [ProjectTask::class, $project]),
                'update' => $user->can('planning.update'),
                'delete' => $user->can('planning.delete'),
                'update_progress' => $user->can('planning.update_progress'),
                'view_costs' => $withCosts,
                'import' => $user->can('planning.import'),
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
        $data = $request->taskData($request->user());
        $task = $this->planning->saveTask($project, $data);
        $label = ! empty($data['as_head']) || $task->isPlanningHead() ? 'Planning head' : 'Activity';

        return back()->with('success', "{$label} {$task->wbs_code} created.");
    }

    public function update(ProjectTaskRequest $request, Project $project, ProjectTask $task): RedirectResponse
    {
        $this->planning->saveTask($project, $request->taskData($request->user()), $task);

        return back()->with('success', 'Planning updated.');
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

    public function saveBoqMaps(Request $request, Project $project, ProjectTask $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'maps' => ['present', 'array', 'max:50'],
            'maps.*.id' => ['nullable', 'integer'],
            'maps.*.boq_item_id' => ['required', 'integer'],
            'maps.*.tracking_basis' => ['required', Rule::enum(PlanningTrackingBasis::class)],
            'maps.*.mapping_method' => ['nullable', Rule::enum(PlanningBoqMappingMethod::class)],
            'maps.*.planning_qty' => ['required', 'numeric', 'gt:0'],
            'maps.*.allocated_boq_qty' => ['nullable', 'numeric', 'min:0'],
            'maps.*.is_primary' => ['nullable', 'boolean'],
            'maps.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->boqMaps->saveMapsForTask($task, $data['maps'], $request->user());

        return back()->with('success', 'BOQ mapping saved. Allocation checked on the server.');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, BoqPlanningMap>|null  $maps
     * @return array<string, mixed>
     */
    private function present(ProjectTask $t, bool $withCosts, ?Collection $maps = null): array
    {
        $boqMaps = [];
        if (! $t->isPlanningHead()) {
            $boqMaps = $maps !== null
                ? $maps->map(fn (BoqPlanningMap $m) => [
                    'id' => $m->id,
                    'boq_item_id' => $m->boq_item_id,
                    'boq_id' => $m->boq_id,
                    'boq_label' => $m->boq ? ($m->boq->boq_number.' / R'.$m->boq->version) : null,
                    'item_name' => $m->item?->name,
                    'item_code' => $m->item?->item_code,
                    'tracking_basis' => $m->tracking_basis?->value,
                    'mapping_method' => $m->mapping_method?->value,
                    'planning_qty' => (string) $m->planning_qty,
                    'allocated_boq_qty' => (string) $m->allocated_boq_qty,
                    'planning_unit_symbol' => $m->planning_unit_symbol,
                    'boq_unit_symbol' => $m->boq_unit_symbol,
                    'conversion_factor' => $m->conversion_factor !== null ? (string) $m->conversion_factor : null,
                    'is_primary' => (bool) $m->is_primary,
                    'remarks' => $m->remarks,
                ])->values()->all()
                : $this->boqMaps->mapsForTask($t);
        }

        $data = [
            ...$t->only([
                'id', 'parent_id', 'wbs_code', 'name', 'description', 'assigned_to', 'milestone_id',
                'duration_days', 'unit_id', 'planned_qty', 'daily_target_qty', 'completed_qty', 'hold_quantity', 'progress_percent', 'sort_order',
            ]),
            'is_head' => $t->isPlanningHead(),
            'head_name' => $t->parent?->name,
            'head_code' => $t->parent?->wbs_code,
            'head_progress' => $t->isPlanningHead()
                ? app(PlanningHeadProgressService::class)->summarize($t)
                : null,
            'balance_qty' => $t->balanceQuantity(),
            'completion_percent' => $t->completionPercent(),
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
            'predecessors' => $t->dependencies->map(fn (TaskDependency $d) => [
                'id' => $d->id,
                'predecessor_id' => $d->predecessor_id,
                'wbs_code' => $d->predecessor?->wbs_code,
                'name' => $d->predecessor?->name,
                'type' => $d->type->value,
                'lag_days' => $d->lag_days,
            ])->values()->all(),
            'boq_maps' => $boqMaps,
            'boq_map_summary' => null,
        ];

        if ($boqMaps !== []) {
            $primary = collect($boqMaps)->firstWhere('is_primary', true) ?? $boqMaps[0];
            $label = trim(($primary['item_code'] ? $primary['item_code'].' ' : '').($primary['item_name'] ?? 'BOQ'));
            if (($primary['tracking_basis'] ?? null) === PlanningTrackingBasis::Nos->value) {
                $data['boq_map_summary'] = 'BOQ: '.$label.' · '.$primary['planning_qty'].' Nos'
                    .($primary['allocated_boq_qty'] ? ' → '.$primary['allocated_boq_qty'].' '.($primary['boq_unit_symbol'] ?? '') : '');
            } else {
                $data['boq_map_summary'] = 'BOQ: '.$label.' · '.$primary['planning_qty'].' '.($primary['planning_unit_symbol'] ?? '');
            }
        }

        if ($withCosts) {
            $data['budget_amount'] = $t->budget_amount;
            $data['actual_cost'] = $t->actual_cost;
        }

        return $data;
    }

    /**
     * @param  Collection<int, ProjectTask>  $tasks
     * @return array<string, int>
     */
    private function summary($tasks): array
    {
        $heads = $tasks->whereNull('parent_id')->count();
        $activities = $tasks->filter(fn (ProjectTask $t) => $t->unit_id !== null || $t->parent_id !== null || (float) $t->planned_qty > 0);

        return [
            'heads' => $heads,
            'activities' => $activities->count(),
            'not_started' => $activities->where('status', TaskStatus::NotStarted)->count(),
            'in_progress' => $activities->where('status', TaskStatus::InProgress)->count(),
            'on_hold' => $activities->filter(fn (ProjectTask $t) => $t->status === TaskStatus::OnHold || (float) ($t->hold_quantity ?? 0) > 0)->count(),
            'completed' => $activities->where('status', TaskStatus::Completed)->count(),
            'delayed' => $activities->where('status', TaskStatus::Delayed)->count(),
        ];
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
            'heads' => $project->tasks()->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'wbs_code', 'name'])
                ->map(fn (ProjectTask $h) => ['value' => $h->id, 'label' => trim($h->wbs_code.' '.$h->name)])->all(),
            'statuses' => TaskStatus::options(),
            'priorities' => TaskPriority::options(),
            'dependencyTypes' => DependencyType::options(),
            'boq_items' => $this->boqMaps->eligibleBoqItems($project),
            'tracking_bases' => PlanningTrackingBasis::options(),
            'mapping_methods' => PlanningBoqMappingMethod::options(),
            'transitions' => collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $s) => [
                $s->value => array_map(fn (TaskStatus $t) => $t->value, $s->allowedTransitions()),
            ])->all(),
            'view_costs' => $withCosts,
        ];
    }
}

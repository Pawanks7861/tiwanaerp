<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Planning\TaskPriority;
use App\Enums\Planning\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskResource;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assignee' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'milestone_id' => ['nullable', 'integer'],
            'delayed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $assignee = $filters['assignee'] ?? $filters['assigned_to'] ?? null;
        $today = now()->toDateString();

        $rows = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with([
                'assignee:id,name',
                'unit:id,symbol,name',
                'milestone:id,name,due_date',
                'boqItem:id,boq_id,item_code,name,quantity,unit_id,line_uid',
                'boqItem.unit:id,symbol',
                'parent:id,wbs_code,name',
            ])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('wbs_code', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['priority']), fn (Builder $q) => $q->where('priority', $filters['priority']))
            ->when($assignee, fn (Builder $q) => $q->where('assigned_to', (int) $assignee))
            ->when(! empty($filters['milestone_id']), fn (Builder $q) => $q->where('milestone_id', (int) $filters['milestone_id']))
            ->when($request->boolean('delayed'), function (Builder $q) use ($today) {
                $q->where(function (Builder $w) use ($today) {
                    $w->where('status', TaskStatus::Delayed->value)
                        ->orWhere(function (Builder $inner) use ($today) {
                            $inner->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::OnHold->value])
                                ->whereNotNull('planned_finish')
                                ->whereDate('planned_finish', '<', $today);
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ProjectTaskResource::collection($rows);
    }

    public function show(Project $project, ProjectTask $task): ProjectTaskResource
    {
        abort_unless((int) $task->project_id === (int) $project->id, 404);
        Gate::authorize('view', $task);

        $task->load([
            'assignee:id,name',
            'unit:id,symbol,name',
            'milestone:id,name,due_date',
            'parent:id,wbs_code,name',
            'boqItem:id,boq_id,item_code,name,quantity,unit_id,line_uid',
            'boqItem.unit:id,symbol',
            'dependencies.predecessor:id,wbs_code,name',
            'progressEntries' => fn ($q) => $q->limit(20),
        ]);

        return new ProjectTaskResource($task);
    }
}

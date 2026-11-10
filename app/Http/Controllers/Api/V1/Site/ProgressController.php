<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgressEntryResource;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Queries\Reports\ProgressQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProgressController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('planning.view') || $request->user()->can('dpr.view'), 403);

        $filters = $request->validate([
            'task_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = ProgressEntry::query()
            ->where('project_id', $project->id)
            ->with([
                'task:id,wbs_code,name',
                'boqItem:id,line_uid,item_code,name',
            ])
            ->when(! empty($filters['task_id']), function ($q) use ($filters, $project) {
                $taskId = (int) $filters['task_id'];
                abort_unless(ProjectTask::query()->where('project_id', $project->id)->whereKey($taskId)->exists(), 404);
                $q->where('task_id', $taskId);
            })
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('entry_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('entry_date', '<=', $filters['to']))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ProgressEntryResource::collection($rows);
    }

    public function forTask(Request $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        abort_unless((int) $task->project_id === (int) $project->id, 404);
        Gate::authorize('view', $task);

        $request->merge(['task_id' => $task->id]);

        return $this->index($request, $project);
    }

    public function project(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('planning.view') || $request->user()->can('dpr.view'), 403);

        $asOf = $request->validate(['as_of' => ['nullable', 'date']])['as_of'] ?? now()->toDateString();
        $summary = app(ProgressQuery::class)->summary(
            (int) $project->company_id,
            [(int) $project->id],
            substr((string) $asOf, 0, 10),
        );

        return response()->json([
            'data' => [
                'as_of' => substr((string) $asOf, 0, 10),
                'project_progress_percent' => $summary['per_project'][(int) $project->id] ?? $summary['overall'],
                'tasks' => $summary['tasks'],
                'leaf_tasks' => $summary['leaf_tasks'],
                'completed' => $summary['completed'],
                'delayed' => $summary['delayed'],
                'in_progress' => $summary['in_progress'],
                'not_started' => $summary['not_started'],
                'on_hold' => $summary['on_hold'],
            ],
        ]);
    }
}

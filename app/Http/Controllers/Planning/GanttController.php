<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Projects\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only Gantt. The database stays authoritative: the chart only renders planned dates,
 * recorded progress and dependency links.
 */
class GanttController extends Controller
{
    public function show(Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        return Inertia::render('Planning/Gantt', [
            'project' => ProjectHeader::for($project),
            'dataUrl' => route('projects.planning.gantt.data', $project),
        ]);
    }

    public function data(Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        $tasks = $project->tasks()
            ->with(['dependencies:id,predecessor_id,successor_id,type,lag_days', 'assignee:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(3000)
            ->get();

        $scheduled = $tasks->filter(fn (ProjectTask $t) => $t->planned_start !== null && $t->planned_finish !== null);
        $scheduledIds = $scheduled->pluck('id')->flip();

        return response()->json([
            'tasks' => $scheduled->map(fn (ProjectTask $t) => [
                'id' => (string) $t->id,
                'name' => "{$t->wbs_code} {$t->name}",
                'start' => $t->planned_start->toDateString(),
                'end' => $t->planned_finish->toDateString(),
                'progress' => (float) $t->progress_percent,
                'dependencies' => $t->dependencies
                    ->filter(fn (TaskDependency $d) => $scheduledIds->has($d->predecessor_id))
                    ->map(fn (TaskDependency $d) => (string) $d->predecessor_id)
                    ->values()->all(),
                'custom_class' => 'gantt-status-'.$t->status->value,
                'wbs_code' => $t->wbs_code,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'assignee' => $t->assignee?->name,
                'parent_id' => $t->parent_id,
                'links' => $t->dependencies->map(fn (TaskDependency $d) => [
                    'predecessor_id' => $d->predecessor_id,
                    'type' => $d->type->value,
                    'lag_days' => $d->lag_days,
                ])->values()->all(),
            ])->values()->all(),
            'unscheduled' => $tasks->count() - $scheduled->count(),
        ]);
    }
}

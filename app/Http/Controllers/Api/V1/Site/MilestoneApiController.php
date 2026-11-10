<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectMilestoneResource;
use App\Models\Planning\ProjectMilestone;
use App\Models\Projects\Project;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MilestoneApiController extends Controller
{
    public function __construct(private readonly PlanningService $planning) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ProjectMilestone::class, $project]);

        $rows = $project->milestones()
            ->withCount('tasks')
            ->orderBy('sort_order')
            ->orderBy('due_date')
            ->get();

        return ProjectMilestoneResource::collection($rows);
    }

    public function show(Project $project, ProjectMilestone $milestone): ProjectMilestoneResource
    {
        abort_unless((int) $milestone->project_id === (int) $project->id, 404);
        Gate::authorize('view', $milestone);

        return new ProjectMilestoneResource($milestone->loadCount('tasks'));
    }

    public function complete(Request $request, Project $project, ProjectMilestone $milestone): JsonResponse
    {
        abort_unless((int) $milestone->project_id === (int) $project->id, 404);
        Gate::authorize('update', $milestone);

        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $this->planning->setMilestoneCompleted($milestone, (bool) $data['completed']);

        return response()->json([
            'data' => new ProjectMilestoneResource($milestone->fresh()->loadCount('tasks')),
        ]);
    }
}

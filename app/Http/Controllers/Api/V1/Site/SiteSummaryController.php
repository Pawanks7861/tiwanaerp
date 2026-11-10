<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Planning\TaskStatus;
use App\Enums\SiteExecution\DprStatus;
use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Http\Controllers\Controller;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\SiteDiary;
use App\Queries\Reports\ProgressQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SiteSummaryController extends Controller
{
    public function __invoke(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $today = now()->toDateString();
        $openStatuses = [
            TaskStatus::NotStarted->value,
            TaskStatus::InProgress->value,
            TaskStatus::Delayed->value,
            TaskStatus::OnHold->value,
        ];

        $openTasks = (int) ProjectTask::query()
            ->where('project_id', $project->id)
            ->whereIn('status', $openStatuses)
            ->count();

        $delayedTasks = (int) ProjectTask::query()
            ->where('project_id', $project->id)
            ->where(function ($q) use ($today) {
                $q->where('status', TaskStatus::Delayed->value)
                    ->orWhere(function ($w) use ($today) {
                        $w->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::OnHold->value])
                            ->whereNotNull('planned_finish')
                            ->whereDate('planned_finish', '<', $today);
                    });
            })
            ->count();

        $todayDiaries = (int) SiteDiary::query()
            ->where('project_id', $project->id)
            ->whereDate('diary_date', $today)
            ->count();

        $pendingDiaryReview = (int) SiteDiary::query()
            ->where('project_id', $project->id)
            ->where('status', SiteDiaryStatus::Submitted->value)
            ->count();

        $pendingDiaryApproval = (int) SiteDiary::query()
            ->where('project_id', $project->id)
            ->where('status', SiteDiaryStatus::Reviewed->value)
            ->count();

        $todayDpr = Dpr::query()
            ->where('project_id', $project->id)
            ->whereDate('dpr_date', $today)
            ->first(['id', 'status', 'dpr_number']);

        $pendingDprApproval = (int) Dpr::query()
            ->where('project_id', $project->id)
            ->where('status', DprStatus::Submitted->value)
            ->count();

        $progress = app(ProgressQuery::class)->summary(
            (int) $project->company_id,
            [(int) $project->id],
            $today,
        );

        return response()->json([
            'data' => [
                'open_tasks' => $openTasks,
                'delayed_tasks' => $delayedTasks,
                'today_diaries' => $todayDiaries,
                'pending_diary_review' => $pendingDiaryReview,
                'pending_diary_approval' => $pendingDiaryApproval,
                'today_dpr_exists' => $todayDpr !== null,
                'today_dpr' => $todayDpr ? [
                    'id' => $todayDpr->id,
                    'dpr_number' => $todayDpr->dpr_number,
                    'status' => $todayDpr->status?->value ?? $todayDpr->status,
                ] : null,
                'pending_dpr_approval' => $pendingDprApproval,
                'project_progress_percent' => $progress['per_project'][(int) $project->id] ?? $progress['overall'],
                'can' => [
                    'view_planning' => Gate::allows('viewAny', [ProjectTask::class, $project]),
                    'create_diary' => Gate::allows('create', [SiteDiary::class, $project]),
                    'create_dpr' => Gate::allows('create', [Dpr::class, $project]),
                    'view_progress' => $request->user()->can('planning.view') || $request->user()->can('dpr.view'),
                ],
            ],
        ]);
    }
}

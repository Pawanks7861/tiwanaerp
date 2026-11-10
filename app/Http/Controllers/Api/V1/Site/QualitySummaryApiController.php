<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Enums\Quality\NcrStatus;
use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class QualitySummaryApiController extends Controller
{
    public function __invoke(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [QualityInspection::class, $project]);

        $statusRows = $project->qualityInspections()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $resultRows = $project->qualityInspections()
            ->where('status', InspectionStatus::Completed)
            ->select('result', DB::raw('count(*) as aggregate'))
            ->groupBy('result')
            ->pluck('aggregate', 'result');

        $ncrRows = $project->ncrs()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $today = now()->toDateString();
        $overdueNcrs = $project->ncrs()
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', $today)
            ->whereNotIn('status', [NcrStatus::Verified->value, NcrStatus::Closed->value])
            ->count();

        $user = $request->user();

        return response()->json([
            'data' => [
                'requested' => (int) ($statusRows[InspectionStatus::Requested->value] ?? 0),
                'scheduled' => (int) ($statusRows[InspectionStatus::Scheduled->value] ?? 0),
                'completed' => (int) ($statusRows[InspectionStatus::Completed->value] ?? 0),
                'failed' => (int) ($resultRows[InspectionResult::Failed->value] ?? 0),
                'conditional' => (int) ($resultRows[InspectionResult::Conditional->value] ?? 0),
                'open_ncrs' => (int) (($ncrRows[NcrStatus::Open->value] ?? 0) + ($ncrRows[NcrStatus::InProgress->value] ?? 0)),
                'overdue_ncrs' => $overdueNcrs,
                'pending_verification' => (int) ($ncrRows[NcrStatus::Resolved->value] ?? 0),
                'can' => [
                    'create_inspection' => $user->can('create', [QualityInspection::class, $project]),
                    'raise_ncr' => $user->can('create', [Ncr::class, $project]),
                ],
            ],
        ]);
    }
}

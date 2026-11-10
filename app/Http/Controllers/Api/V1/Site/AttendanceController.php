<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Services\Labour\LabourAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    public function __construct(private readonly LabourAttendanceService $attendance) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $logs = LabourAttendance::query()
            ->where('project_id', $project->id)
            ->with(['labour.trade:id,name', 'site:id,name'])
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return AttendanceResource::collection($logs);
    }

    public function approve(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('approve', [LabourAttendance::class, $project]);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = $this->attendance->approve($project, $ids, $request->user());

        return response()->json([
            'message' => $count > 0 ? "{$count} attendance row(s) approved." : 'Nothing to approve.',
            'approved_count' => $count,
        ]);
    }

    public function unapprove(Request $request, Project $project, LabourAttendance $attendance): AttendanceResource
    {
        abort_unless((int) $attendance->project_id === (int) $project->id, 404);
        Gate::authorize('unapprove', $attendance);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->attendance->unapprove($attendance, $request->user(), $reason);

        return new AttendanceResource($attendance->fresh()->load(['labour.trade:id,name', 'site:id,name']));
    }

    public function destroy(Project $project, LabourAttendance $attendance): JsonResponse
    {
        abort_unless((int) $attendance->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $attendance);
        $this->attendance->delete($attendance);

        return response()->json(['message' => 'Attendance entry removed.']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
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
}

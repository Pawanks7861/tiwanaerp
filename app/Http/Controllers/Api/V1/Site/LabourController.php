<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Labour\LabourPresenter;
use App\Http\Requests\Api\Site\StoreLabourAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\LabourPaymentResource;
use App\Http\Resources\LabourRosterResource;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Projects\Project;
use App\Services\Labour\LabourAttendanceService;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LabourController extends Controller
{
    public function __construct(private readonly LabourAttendanceService $attendance) {}

    public function summary(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);
        $user = $request->user();
        $today = now()->toDateString();

        $active = Labour::query()->active()->where('current_project_id', $project->id)->count();
        $todayRows = LabourAttendance::query()
            ->where('project_id', $project->id)
            ->whereDate('attendance_date', $today);

        $present = (clone $todayRows)->where('status', AttendanceStatus::Present)->count();
        $absent = (clone $todayRows)->where('status', AttendanceStatus::Absent)->count();
        $half = (clone $todayRows)->where('status', AttendanceStatus::HalfDay)->count();
        $leave = (clone $todayRows)->where('status', AttendanceStatus::Leave)->count();

        $pendingAttendance = LabourAttendance::query()
            ->where('project_id', $project->id)
            ->where('approval_status', AttendanceApproval::Marked)
            ->count();

        $pendingPayments = 0;
        if ($user->can('viewAny', [LabourPayment::class, $project])) {
            $pendingPayments = LabourPayment::query()
                ->where('project_id', $project->id)
                ->where('status', LabourPaymentStatus::Submitted)
                ->count();
        }

        return response()->json([
            'data' => [
                'active_labour' => $active,
                'present_today' => $present,
                'absent_today' => $absent,
                'half_day_today' => $half,
                'leave_today' => $leave,
                'pending_attendance_approval' => $pendingAttendance,
                'pending_labour_payments' => $pendingPayments,
                'can' => [
                    'mark_attendance' => $user->can('mark', [LabourAttendance::class, $project]),
                    'approve_attendance' => $user->can('approve', [LabourAttendance::class, $project]),
                    'manage_payments' => $user->can('create', [LabourPayment::class, $project]),
                    'manage_register' => $user->can('create', Labour::class),
                ],
            ],
        ]);
    }

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

    public function roster(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);
        $wages = LabourPresenter::seesWages($request->user());

        $crew = Labour::query()->active()
            ->where('current_project_id', $project->id)
            ->with('trade:id,name')
            ->orderBy('name')
            ->get();

        $due = LabourAdvance::query()
            ->whereIn('labour_id', $crew->modelKeys())
            ->selectRaw('labour_id, SUM(amount) as advanced, SUM(recovered_amount) as recovered')
            ->groupBy('labour_id')
            ->get()
            ->keyBy('labour_id');

        return LabourRosterResource::collection($crew->map(function (Labour $labour) use ($due) {
            $row = $due->get($labour->id);

            return [
                'id' => $labour->id,
                'code' => $labour->code,
                'name' => $labour->name,
                'mobile' => $labour->mobile,
                'trade' => $labour->trade?->name,
                'daily_wage' => $wages ? $labour->daily_wage : null,
                'ot_rate_per_hour' => $wages ? $labour->ot_rate_per_hour : null,
                'advance_due' => $row
                    ? Decimal::of($row->advanced)->minus($row->recovered)->toMoney()
                    : '0.00',
            ];
        })->values());
    }

    public function attendance(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);

        $date = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ])['date'] ?? now()->toDateString();

        return response()->json($this->daySheet($project, $date));
    }

    public function storeAttendance(StoreLabourAttendanceRequest $request, Project $project): JsonResponse
    {
        $this->attendance->mark($project, $request->payload(), $request->user());

        return response()->json($this->daySheet($project, $request->validated('date')));
    }

    public function payments(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [LabourPayment::class, $project]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $page = LabourPayment::query()
            ->where('project_id', $project->id)
            ->withCount('lines')
            ->latest('id')
            ->paginate($perPage);

        return LabourPaymentResource::collection($page);
    }

    /**
     * @return array{date: string, data: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function daySheet(Project $project, string $date): array
    {
        $user = request()->user();
        $wages = $user && LabourPresenter::seesWages($user);
        $amounts = $user && LabourPresenter::seesAttendanceAmounts($user);

        $day = LabourAttendance::query()
            ->where('project_id', $project->id)
            ->whereDate('attendance_date', $date)
            ->with(['labour.trade:id,name'])
            ->orderBy('id')
            ->get();

        return [
            'date' => $date,
            'data' => $day->map(fn (LabourAttendance $row) => [
                'id' => $row->id,
                'labourer_id' => $row->labour_id,
                'status' => $row->status->short(),
                'status_label' => $row->status->label(),
                'approval_status' => $row->approval_status->value,
                'working_hours' => $row->working_hours,
                'ot_hours' => $row->ot_hours,
                'wage_amount' => $amounts ? $row->wage_amount : null,
                'ot_amount' => $amounts ? $row->ot_amount : null,
                'labourer' => $row->labour ? [
                    'id' => $row->labour->id,
                    'code' => $row->labour->code,
                    'name' => $row->labour->name,
                    'trade' => $row->labour->trade?->name,
                    'daily_wage' => $wages ? $row->labour->daily_wage : null,
                ] : null,
            ])->values()->all(),
            'summary' => [
                'present' => $day->where('status', AttendanceStatus::Present)->count(),
                'absent' => $day->where('status', AttendanceStatus::Absent)->count(),
                'half' => $day->where('status', AttendanceStatus::HalfDay)->count(),
                'leave' => $day->where('status', AttendanceStatus::Leave)->count(),
                'wages' => $amounts
                    ? Decimal::sum($day->map(fn (LabourAttendance $row) => $row->totalAmount())->all())->toMoney()
                    : null,
            ],
        ];
    }
}

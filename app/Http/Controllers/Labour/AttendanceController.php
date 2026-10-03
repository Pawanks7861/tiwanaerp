<?php

namespace App\Http\Controllers\Labour;

use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Services\Labour\LabourAttendanceService;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily attendance sheet of a project: the crew plus anyone already marked that day. Marking is
 * a bulk upsert; approval (bulk) posts the labour cost.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly LabourAttendanceService $attendance) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);
        $user = $request->user();

        $filters = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'site_id' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($filters['date'] ?? today())->toDateString();
        $siteId = isset($filters['site_id']) && Site::query()->where('project_id', $project->id)->whereKey($filters['site_id'])->exists()
            ? (int) $filters['site_id'] : null;

        $marked = LabourAttendance::query()->whereDate('attendance_date', $date)
            ->where('project_id', $project->id)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with(['approver:id,name', 'payment:id,payment_number'])
            ->get()->keyBy('labour_id');

        $crew = Labour::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('current_project_id', $project->id)->where('is_active', true))
                ->orWhereIn('id', $marked->keys()))
            ->with('trade:id,name')
            ->orderBy('name')->get();

        $elsewhere = LabourAttendance::query()->whereDate('attendance_date', $date)
            ->where('project_id', '!=', $project->id)
            ->whereIn('labour_id', $crew->modelKeys())
            ->with('project:id,code')->get()->keyBy('labour_id');

        // With a site filter, crew marked at another site of this project that day are read-only.
        $otherSite = $siteId ? LabourAttendance::query()->whereDate('attendance_date', $date)
            ->where('project_id', $project->id)->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', '!=', $siteId))
            ->pluck('labour_id')->flip() : collect();

        $rows = $crew->map(function (Labour $l) use ($marked, $elsewhere, $otherSite) {
            $a = $marked->get($l->id);

            return [
                'labour_id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'trade' => $l->trade?->name,
                'daily_wage' => $l->daily_wage,
                'ot_rate' => $l->ot_rate_per_hour,
                'elsewhere' => $elsewhere->get($l->id)?->project?->code ?? ($otherSite->has($l->id) ? 'another site' : null),
                'attendance' => $a ? [
                    'id' => $a->id,
                    'status' => $a->status->value,
                    'punch_in' => $a->punch_in ? substr($a->punch_in, 0, 5) : null,
                    'punch_out' => $a->punch_out ? substr($a->punch_out, 0, 5) : null,
                    'working_hours' => $a->working_hours,
                    'ot_hours' => $a->ot_hours,
                    'daily_wage' => $a->daily_wage,
                    'ot_rate' => $a->ot_rate,
                    'wage_amount' => $a->wage_amount,
                    'ot_amount' => $a->ot_amount,
                    'task_id' => $a->task_id,
                    'remarks' => $a->remarks,
                    'approval_status' => $a->approval_status->value,
                    'approved_by' => $a->approver?->name,
                    'payment_number' => $a->payment?->payment_number,
                ] : null,
            ];
        })->values();

        $pendingDates = LabourAttendance::query()->where('project_id', $project->id)
            ->where('approval_status', AttendanceApproval::Marked)
            ->selectRaw('DATE(attendance_date) as day, COUNT(*) as rows_count')
            ->groupByRaw('DATE(attendance_date)')->orderByRaw('DATE(attendance_date) desc')->limit(31)->get()
            ->map(fn ($r) => ['date' => (string) $r->day, 'count' => (int) $r->rows_count])->all();

        $day = $marked->values();

        return Inertia::render('Labour/Attendance', [
            'project' => ProjectHeader::for($project),
            'date' => $date,
            'siteId' => $siteId,
            'rows' => $rows->all(),
            'summary' => [
                'present' => $day->where('status', AttendanceStatus::Present)->count(),
                'half_day' => $day->where('status', AttendanceStatus::HalfDay)->count(),
                'absent' => $day->where('status', AttendanceStatus::Absent)->count(),
                'leave' => $day->where('status', AttendanceStatus::Leave)->count(),
                'marked' => $day->where('approval_status', AttendanceApproval::Marked)->count(),
                'approved' => $day->where('approval_status', AttendanceApproval::Approved)->count(),
                'wages' => Decimal::sum($day->map(fn (LabourAttendance $a) => $a->totalAmount())->all())->toMoney(),
            ],
            'pendingDates' => $pendingDates,
            'options' => [
                'sites' => ProcurementPresenter::siteOptions($project),
                'tasks' => ProcurementPresenter::taskOptions($project),
                'statuses' => AttendanceStatus::options(),
                'labours' => Labour::query()->active()->whereNotIn('id', $crew->modelKeys())->orderBy('name')->get(['id', 'code', 'name', 'daily_wage', 'ot_rate_per_hour'])
                    ->map(fn (Labour $l) => ['value' => $l->id, 'label' => $l->name, 'description' => $l->code, 'code' => $l->code, 'daily_wage' => $l->daily_wage, 'ot_rate' => $l->ot_rate_per_hour])->all(),
            ],
            'today' => now()->toDateString(),
            'can' => [
                'mark' => $user->can('mark', [LabourAttendance::class, $project]),
                'approve' => $user->can('approve', [LabourAttendance::class, $project]),
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('mark', [LabourAttendance::class, $project]);

        $data = $request->validate([
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'site_id' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.labour_id' => ['required', 'integer'],
            'rows.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'rows.*.punch_in' => ['nullable', 'string', 'max:8'],
            'rows.*.punch_out' => ['nullable', 'string', 'max:8'],
            'rows.*.working_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.ot_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.task_id' => ['nullable', 'integer'],
            'rows.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['rows.*.status' => 'status', 'rows.*.labour_id' => 'labourer']);

        $count = $this->attendance->mark($project, $data, $request->user());

        return back()->with('success', "Attendance saved for {$count} ".str('labourer')->plural($count).'.');
    }

    public function approve(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('approve', [LabourAttendance::class, $project]);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = $this->attendance->approve($project, $ids, $request->user());

        return back()->with('success', $count > 0
            ? "{$count} attendance ".str('row')->plural($count).' approved; labour cost posted.'
            : 'Nothing to approve: the selected rows are already approved.');
    }

    public function unapprove(Request $request, Project $project, LabourAttendance $attendance): RedirectResponse
    {
        Gate::authorize('unapprove', $attendance);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->attendance->unapprove($attendance, $request->user(), $reason);

        return back()->with('success', 'Attendance un-approved; its labour cost was reversed.');
    }

    public function destroy(Project $project, LabourAttendance $attendance): RedirectResponse
    {
        Gate::authorize('delete', $attendance);

        $this->attendance->delete($attendance);

        return back()->with('success', 'Attendance entry removed.');
    }
}

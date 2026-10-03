<?php

namespace App\Services\Labour;

use App\Enums\CostHead;
use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily attendance: marked (bulk, by the site) → approved (manager). Wage and OT are snapshotted
 * from the labour register when marked and recalculated on every edit while marked; approval
 * freezes them and posts the day's wage + OT as 'labour' project cost (one ledger row per
 * attendance row, idempotent). Un-approval reverses that row.
 */
class LabourAttendanceService
{
    use ResolvesProjectRefs;

    public function __construct(private readonly ProjectCostLedgerService $costs) {}

    /**
     * Upsert the day's sheet for one project (and optional site).
     *
     * @param  array<string, mixed>  $data  attendance_date, site_id, latitude, longitude, rows[]
     * @return int rows saved
     */
    public function mark(Project $project, array $data, User $user): int
    {
        $date = CarbonImmutable::parse($data['attendance_date'])->startOfDay();
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['attendance_date' => 'Attendance cannot be marked for a future date.']);
        }
        $site = $this->site($project, $data['site_id'] ?? null);
        $rows = array_values($data['rows'] ?? []);
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'Add at least one labourer.']);
        }

        return DB::transaction(function () use ($project, $data, $user, $date, $site, $rows) {
            $saved = 0;
            $seen = [];

            foreach ($rows as $index => $row) {
                $key = "rows.{$index}";
                $labourId = (int) ($row['labour_id'] ?? 0);
                if (isset($seen[$labourId])) {
                    throw ValidationException::withMessages(["{$key}.labour_id" => 'This labourer appears twice on the sheet.']);
                }
                $seen[$labourId] = true;

                $existing = LabourAttendance::query()
                    ->where('labour_id', $labourId)->whereDate('attendance_date', $date)
                    ->lockForUpdate()->first();

                $labour = $existing
                    ? Labour::query()->withTrashed()->find($labourId)
                    : Labour::query()->active()->find($labourId);
                if ($labour === null) {
                    throw ValidationException::withMessages(["{$key}.labour_id" => 'Choose an active labourer of this company.']);
                }

                if ($existing && (int) $existing->project_id !== (int) $project->id) {
                    $code = Project::query()->whereKey($existing->project_id)->value('code');
                    throw ValidationException::withMessages(["{$key}.labour_id" => "{$labour->name} is already marked on project {$code} for this date."]);
                }
                if ($existing?->isApproved()) {
                    throw ValidationException::withMessages(["{$key}.labour_id" => "{$labour->name}'s attendance for this date is already approved."]);
                }

                $values = $this->calculate($project, $labour, $row, $key);
                $attendance = $existing ?? new LabourAttendance;
                $attendance->forceFill([
                    ...$values,
                    'project_id' => $project->id,
                    'site_id' => $site?->id,
                    'labour_id' => $labour->id,
                    'attendance_date' => $date->toDateString(),
                    'latitude' => $data['latitude'] ?? $attendance->latitude,
                    'longitude' => $data['longitude'] ?? $attendance->longitude,
                    'approval_status' => AttendanceApproval::Marked,
                    'marked_by' => $user->id,
                ])->save();
                $saved++;
            }

            return $saved;
        });
    }

    public function delete(LabourAttendance $attendance): void
    {
        DB::transaction(function () use ($attendance) {
            $locked = LabourAttendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $locked->delete();
        });
    }

    /**
     * Approve marked rows of the project; each approval posts the day's labour cost.
     *
     * @param  list<int>  $ids
     * @return int rows approved
     */
    public function approve(Project $project, array $ids, User $user): int
    {
        return DB::transaction(function () use ($project, $ids, $user) {
            $rows = LabourAttendance::query()
                ->where('project_id', $project->id)
                ->whereIn('id', array_map('intval', $ids))
                ->where('approval_status', AttendanceApproval::Marked)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->forceFill([
                    'approval_status' => AttendanceApproval::Approved,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ])->save();

                $this->postCost($row, $user->id);
            }

            return $rows->count();
        });
    }

    /**
     * Correction of an approved day still outside any payment: reverses its labour cost and
     * returns it to marked so it can be edited and approved again.
     */
    public function unapprove(LabourAttendance $attendance, User $user, string $reason): void
    {
        DB::transaction(function () use ($attendance, $user, $reason) {
            $locked = LabourAttendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isApproved()) {
                throw ValidationException::withMessages(['attendance' => 'Only approved attendance can be un-approved.']);
            }
            if ($locked->labour_payment_id !== null) {
                throw ValidationException::withMessages(['attendance' => 'This day is included in a labour payment and can no longer be un-approved.']);
            }

            $this->costs->reverseActive($locked, CostHead::Labour, "Attendance un-approved: {$reason}", $user->id);

            $locked->forceFill([
                'approval_status' => AttendanceApproval::Marked,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();
            $locked->writeAudit('unapproved', null, ['reason' => $reason]);
            $attendance->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Server-side wage, OT and hours for one sheet row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function calculate(Project $project, Labour $labour, array $row, string $key): array
    {
        $status = AttendanceStatus::tryFrom((string) ($row['status'] ?? ''))
            ?? throw ValidationException::withMessages(["{$key}.status" => 'Choose present, half day, absent or leave.']);

        $punchIn = $this->time($row['punch_in'] ?? null, "{$key}.punch_in");
        $punchOut = $this->time($row['punch_out'] ?? null, "{$key}.punch_out");
        $dailyWage = Decimal::of($labour->daily_wage);
        $otRate = Decimal::of($labour->ot_rate_per_hour);

        if (! $status->isWorking()) {
            return [
                'status' => $status,
                'task_id' => $this->task($project, $row['task_id'] ?? null, "{$key}.task_id")?->id,
                'punch_in' => null,
                'punch_out' => null,
                'working_hours' => '0.00',
                'ot_hours' => '0.00',
                'daily_wage' => $dailyWage->toMoney(),
                'ot_rate' => $otRate->toRate(),
                'wage_amount' => '0.00',
                'ot_amount' => '0.00',
                'remarks' => $row['remarks'] ?? null,
            ];
        }

        $hours = $this->workingHours($status, $punchIn, $punchOut, $row['working_hours'] ?? null, $key);
        $otHours = $this->amount($row['ot_hours'] ?? null, "{$key}.ot_hours", 2, '24');
        if ($punchIn !== null && $otHours->greaterThan($hours)) {
            throw ValidationException::withMessages(["{$key}.ot_hours" => 'Overtime cannot exceed the punched working hours.']);
        }

        return [
            'status' => $status,
            'task_id' => $this->task($project, $row['task_id'] ?? null, "{$key}.task_id")?->id,
            'punch_in' => $punchIn,
            'punch_out' => $punchOut,
            'working_hours' => $hours->round(2)->toMoney(),
            'ot_hours' => $otHours->toMoney(),
            'daily_wage' => $dailyWage->toMoney(),
            'ot_rate' => $otRate->toRate(),
            'wage_amount' => $dailyWage->times($status->wageFactor())->round(2)->toMoney(),
            'ot_amount' => $otHours->times($otRate)->round(2)->toMoney(),
            'remarks' => $row['remarks'] ?? null,
        ];
    }

    /**
     * Hours from punches (punch out before punch in = overnight shift; equal = invalid), else the
     * entered hours, else the status default (8 / 4).
     */
    private function workingHours(AttendanceStatus $status, ?string $in, ?string $out, mixed $entered, string $key): Decimal
    {
        if (($in === null) !== ($out === null)) {
            throw ValidationException::withMessages(["{$key}.punch_out" => 'Enter both punch in and punch out, or neither.']);
        }

        if ($in !== null) {
            $minutes = $this->minutes($out) - $this->minutes($in);
            if ($minutes === 0) {
                throw ValidationException::withMessages(["{$key}.punch_out" => 'Punch out must be different from punch in.']);
            }
            if ($minutes < 0) {
                $minutes += 24 * 60;
            }

            return Decimal::of($minutes)->dividedBy(60)->round(2);
        }

        if (! blank($entered)) {
            return $this->amount($entered, "{$key}.working_hours", 2, '24');
        }

        return Decimal::of($status->defaultHours());
    }

    private function time(mixed $value, string $key): ?string
    {
        if (blank($value)) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
            throw ValidationException::withMessages([$key => 'Enter a time as HH:MM.']);
        }

        return substr($value, 0, 5).':00';
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function postCost(LabourAttendance $row, int $userId): void
    {
        $amount = Decimal::of($row->wage_amount)->plus($row->ot_amount);
        if ($amount->isZero()) {
            return;
        }

        [$boqItemId, $lineUid] = $this->boqRefOfTask($row->task_id);
        $code = Labour::query()->withTrashed()->whereKey($row->labour_id)->value('code');

        $this->costs->post(
            source: $row,
            projectId: $row->project_id,
            head: CostHead::Labour,
            amount: $amount,
            date: $row->attendance_date->toDateString(),
            boqItemId: $boqItemId,
            boqLineUid: $lineUid,
            taskId: $row->task_id,
            remarks: "Attendance {$code} {$row->attendance_date->toDateString()}",
            userId: $userId,
        );
    }
}

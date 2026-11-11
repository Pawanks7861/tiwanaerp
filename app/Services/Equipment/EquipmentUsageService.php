<?php

namespace App\Services\Equipment;

use App\Enums\CostHead;
use App\Enums\Equipment\RateBasis;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily usage logs of assigned equipment. Posting a log fixes its cost and writes the
 * 'equipment' project cost: hourly basis = working hours × rate; daily basis = one day's rate
 * per logged day, whatever the hours (the machine was deployed that day). Idle hours are
 * recorded but not charged on the hourly basis. A posted log is changed by reversing it.
 */
class EquipmentUsageService
{
    use ResolvesProjectRefs;

    public function __construct(private readonly ProjectCostLedgerService $costs) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): EquipmentUsageLog
    {
        return DB::transaction(function () use ($project, $data) {
            $assignment = EquipmentAssignment::query()->where('project_id', $project->id)
                ->whereKey((int) ($data['equipment_assignment_id'] ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['equipment_assignment_id' => 'Choose an equipment assignment of this project.']);

            $log = new EquipmentUsageLog;
            $log->forceFill([
                ...$this->values($project, $assignment, $data, null),
                'project_id' => $project->id,
                'equipment_assignment_id' => $assignment->id,
            ])->save();

            return $log;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EquipmentUsageLog $log, array $data): void
    {
        DB::transaction(function () use ($log, $data) {
            $locked = EquipmentUsageLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['usage' => 'A posted usage log cannot be changed. Reverse the posting first.']);
            }
            $assignment = EquipmentAssignment::query()->findOrFail($locked->equipment_assignment_id);

            $locked->forceFill($this->values(Project::query()->findOrFail($locked->project_id), $assignment, $data, $locked->id))->save();
        });
    }

    public function delete(EquipmentUsageLog $log): void
    {
        DB::transaction(function () use ($log) {
            EquipmentUsageLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * Post unposted logs of the project. Idempotent per log.
     *
     * @param  list<int>  $ids
     */
    public function post(Project $project, array $ids, User $user): int
    {
        return DB::transaction(function () use ($project, $ids, $user) {
            $logs = EquipmentUsageLog::query()->where('project_id', $project->id)
                ->whereIn('id', array_map('intval', $ids))->whereNull('posted_at')
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($logs as $log) {
                $assignment = EquipmentAssignment::query()->with('equipment:id,code,name')->findOrFail($log->equipment_assignment_id);
                $cost = $this->cost($assignment, $log);

                $log->forceFill(['cost_amount' => $cost->toMoney(), 'posted_by' => $user->id, 'posted_at' => now()])->save();

                if ($cost->isPositive()) {
                    $taskId = $log->task_id ?? $assignment->task_id;
                    [$boqItemId, $lineUid] = $this->boqRefOfTask($taskId);
                    $this->costs->post(
                        source: $log,
                        projectId: $log->project_id,
                        head: CostHead::Equipment,
                        amount: $cost,
                        date: $log->log_date->toDateString(),
                        boqItemId: $boqItemId,
                        boqLineUid: $lineUid,
                        taskId: $taskId,
                        remarks: "Usage {$assignment->equipment?->code} {$log->log_date->toDateString()}",
                        userId: $user->id,
                    );
                }
            }

            return $logs->count();
        });
    }

    public function reverse(EquipmentUsageLog $log, User $user, string $reason): void
    {
        DB::transaction(function () use ($log, $user, $reason) {
            $locked = EquipmentUsageLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPosted()) {
                throw ValidationException::withMessages(['usage' => 'Only a posted usage log can be reversed.']);
            }

            $this->costs->reverseActive($locked, CostHead::Equipment, "Usage reversed: {$reason}", $user->id);
            $locked->forceFill(['cost_amount' => null, 'posted_by' => null, 'posted_at' => null])->save();
            $locked->writeAudit('reversed', null, ['reason' => $reason]);
            $log->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function cost(EquipmentAssignment $assignment, EquipmentUsageLog $log): Decimal
    {
        return $assignment->rate_basis === RateBasis::Daily
            ? Decimal::of($assignment->rate)->round(2)
            : Decimal::of($log->working_hours)->times($assignment->rate)->round(2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(Project $project, EquipmentAssignment $assignment, array $data, ?int $ignoreId): array
    {
        $date = (string) $data['log_date'];
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['log_date' => 'Usage cannot be logged for a future date.']);
        }
        $until = $assignment->return_date?->toDateString() ?? now()->toDateString();
        if ($date < $assignment->issue_date->toDateString() || $date > $until) {
            throw ValidationException::withMessages(['log_date' => "The date must fall within the assignment ({$assignment->issue_date->toDateString()} to {$until})."]);
        }
        $duplicate = EquipmentUsageLog::query()->where('equipment_assignment_id', $assignment->id)
            ->whereDate('log_date', $date)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['log_date' => 'Usage is already logged for this equipment on this date.']);
        }

        $opening = blank($data['opening_meter'] ?? null) ? null : $this->amount($data['opening_meter'], 'opening_meter', 2);
        $closing = blank($data['closing_meter'] ?? null) ? null : $this->amount($data['closing_meter'], 'closing_meter', 2);
        if (($opening === null) !== ($closing === null)) {
            throw ValidationException::withMessages(['closing_meter' => 'Enter both meter readings, or neither.']);
        }
        if ($opening !== null && $closing->lessThan($opening)) {
            throw ValidationException::withMessages(['closing_meter' => 'The closing meter cannot be below the opening meter.']);
        }

        $working = blank($data['working_hours'] ?? null) && $opening !== null
            ? $closing->minus($opening)
            : $this->amount($data['working_hours'] ?? null, 'working_hours', 2);
        $idle = $this->amount($data['idle_hours'] ?? null, 'idle_hours', 2);

        [$startTime, $endTime, $totalDuration] = $this->times($data);
        $cap = $totalDuration ?? Decimal::of('24');
        if ($working->plus($idle)->greaterThan($cap)) {
            $label = $totalDuration !== null
                ? 'Working and idle hours cannot exceed the total duration ('.$totalDuration->toMoney().' hours).'
                : 'Working and idle hours cannot exceed 24 in a day.';
            throw ValidationException::withMessages(['working_hours' => $label]);
        }

        return [
            'log_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'total_duration_hours' => $totalDuration?->toMoney(),
            'task_id' => $this->task($project, $data['task_id'] ?? null, 'task_id')?->id ?? $assignment->task_id,
            'opening_meter' => $opening?->toMoney(),
            'closing_meter' => $closing?->toMoney(),
            'working_hours' => $working->toMoney(),
            'idle_hours' => $idle->toMoney(),
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:?string,1:?string,2:?Decimal}
     */
    private function times(array $data): array
    {
        $start = $this->normalizeTime($data['start_time'] ?? null);
        $end = $this->normalizeTime($data['end_time'] ?? null);
        if (($start === null) !== ($end === null)) {
            throw ValidationException::withMessages(['end_time' => 'Enter both start and end time, or neither.']);
        }
        if ($start === null) {
            return [null, null, null];
        }

        $startMinutes = $this->minutes($start);
        $endMinutes = $this->minutes($end);
        $diff = $endMinutes - $startMinutes;
        if ($diff < 0) {
            // Overnight same-log support: end next calendar day.
            $diff += 24 * 60;
        }
        if ($diff === 0) {
            throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
        }

        $hours = Decimal::of((string) $diff)->dividedBy('60')->round(2);

        return [$start, $end, $hours];
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m) !== 1) {
            throw ValidationException::withMessages(['start_time' => 'Use HH:MM time format.']);
        }

        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return ($h * 60) + $m;
    }
}

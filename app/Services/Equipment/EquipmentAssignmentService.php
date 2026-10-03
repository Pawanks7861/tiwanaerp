<?php

namespace App\Services\Equipment;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\RateBasis;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Labour\Labour;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deploying equipment to a project. Issue needs available equipment (row-locked, so two
 * concurrent issues cannot both succeed) and sets it assigned; return sets it available again
 * unless it is under repair or disposed meanwhile.
 */
class EquipmentAssignmentService
{
    use ResolvesProjectRefs;

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(Project $project, array $data): EquipmentAssignment
    {
        return DB::transaction(function () use ($project, $data) {
            $equipment = Equipment::query()->whereKey((int) ($data['equipment_id'] ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['equipment_id' => 'Choose equipment of this company.']);
            if (! $equipment->is_active || $equipment->status !== EquipmentStatus::Available) {
                throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} is not available (status: {$equipment->status->label()})."]);
            }
            if (EquipmentAssignment::query()->where('equipment_id', $equipment->id)->where('status', AssignmentStatus::Active)->exists()) {
                throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} already has an active assignment."]);
            }

            $basis = RateBasis::tryFrom((string) ($data['rate_basis'] ?? '')) ?? RateBasis::Hourly;
            $rate = blank($data['rate'] ?? null)
                ? Decimal::of($basis === RateBasis::Hourly ? $equipment->hourly_rate : $equipment->daily_rate)
                : $this->amount($data['rate'], 'rate', Decimal::RATE_SCALE);

            $assignment = new EquipmentAssignment;
            $assignment->forceFill([
                ...$this->details($project, $data),
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'issue_date' => $data['issue_date'],
                'rate_basis' => $basis,
                'rate' => $rate->toRate(),
                'status' => AssignmentStatus::Active,
            ])->save();

            $equipment->forceFill(['status' => EquipmentStatus::Assigned])->save();

            return $assignment;
        });
    }

    /**
     * Site, task, operator and remarks of an active assignment (the rate terms stay as issued).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EquipmentAssignment $assignment, array $data): void
    {
        DB::transaction(function () use ($assignment, $data) {
            $locked = EquipmentAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['assignment' => 'A returned assignment cannot be changed.']);
            }

            $locked->forceFill($this->details(Project::query()->findOrFail($locked->project_id), $data))->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data  return_date, remarks
     */
    public function returnEquipment(EquipmentAssignment $assignment, array $data, User $user): void
    {
        DB::transaction(function () use ($assignment, $data, $user) {
            $equipment = Equipment::query()->withTrashed()->whereKey($assignment->equipment_id)->lockForUpdate()->firstOrFail();
            $locked = EquipmentAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['assignment' => 'This equipment has already been returned.']);
            }

            $returnDate = (string) $data['return_date'];
            if ($returnDate < $locked->issue_date->toDateString()) {
                throw ValidationException::withMessages(['return_date' => 'The return date cannot be before the issue date.']);
            }
            $lastLog = EquipmentUsageLog::query()->where('equipment_assignment_id', $locked->id)->max('log_date');
            if ($lastLog && $returnDate < substr((string) $lastLog, 0, 10)) {
                throw ValidationException::withMessages(['return_date' => 'Usage is logged up to '.substr((string) $lastLog, 0, 10).'; the return date cannot be earlier.']);
            }

            $locked->forceFill([
                'return_date' => $returnDate,
                'status' => AssignmentStatus::Returned,
                'returned_by' => $user->id,
                'remarks' => $data['remarks'] ?? $locked->remarks,
            ])->save();

            if ($equipment->status === EquipmentStatus::Assigned) {
                $equipment->forceFill(['status' => EquipmentStatus::Available])->save();
            }
            $assignment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(Project $project, array $data): array
    {
        $operatorId = null;
        if (! blank($data['operator_labour_id'] ?? null)) {
            $operatorId = Labour::query()->active()->whereKey((int) $data['operator_labour_id'])->value('id')
                ?? throw ValidationException::withMessages(['operator_labour_id' => 'Choose an active labourer as operator.']);
        }

        return [
            'site_id' => $this->site($project, $data['site_id'] ?? null)?->id,
            'task_id' => $this->task($project, $data['task_id'] ?? null, 'task_id')?->id,
            'operator_labour_id' => $operatorId,
            'operator_name' => $data['operator_name'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}

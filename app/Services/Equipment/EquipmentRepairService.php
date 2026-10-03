<?php

namespace App\Services\Equipment;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\RepairStatus;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repairs: open (equipment under repair) → completed / cancelled (equipment back to assigned if
 * an assignment is still active, else available; disposed equipment stays disposed). The cost is
 * recorded only; the repair bill reaches project cost as a Phase 7 expense (expense_id).
 */
class EquipmentRepairService
{
    use ResolvesProjectRefs;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): EquipmentRepair
    {
        return DB::transaction(function () use ($project, $data) {
            $equipment = Equipment::query()->whereKey((int) ($data['equipment_id'] ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['equipment_id' => 'Choose equipment of this company.']);
            if ($equipment->status === EquipmentStatus::Disposed) {
                throw ValidationException::withMessages(['equipment_id' => 'Disposed equipment cannot be sent for repair.']);
            }
            if (EquipmentRepair::query()->where('equipment_id', $equipment->id)->where('status', RepairStatus::Open)->exists()) {
                throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} already has an open repair."]);
            }
            $elsewhere = EquipmentAssignment::query()->where('equipment_id', $equipment->id)
                ->where('status', AssignmentStatus::Active)->where('project_id', '!=', $project->id)->exists();
            if ($elsewhere) {
                throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} is assigned to another project; record the repair there."]);
            }

            $repair = new EquipmentRepair;
            $repair->forceFill([
                ...$this->values($data),
                'equipment_id' => $equipment->id,
                'project_id' => $project->id,
                'status' => RepairStatus::Open,
            ])->save();

            $equipment->forceFill(['status' => EquipmentStatus::UnderRepair])->save();

            return $repair;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EquipmentRepair $repair, array $data): void
    {
        DB::transaction(function () use ($repair, $data) {
            $locked = EquipmentRepair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['repair' => 'Only an open repair can be changed.']);
            }
            $locked->forceFill($this->values($data))->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data  completed_date, cost, remarks
     */
    public function complete(EquipmentRepair $repair, array $data): void
    {
        $this->close($repair, RepairStatus::Completed, $data);
    }

    public function cancel(EquipmentRepair $repair, string $reason): void
    {
        $this->close($repair, RepairStatus::Cancelled, ['remarks' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function close(EquipmentRepair $repair, RepairStatus $to, array $data): void
    {
        DB::transaction(function () use ($repair, $to, $data) {
            $equipment = Equipment::query()->withTrashed()->whereKey($repair->equipment_id)->lockForUpdate()->firstOrFail();
            $locked = EquipmentRepair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['repair' => 'This repair is already closed.']);
            }

            $values = ['status' => $to, 'remarks' => $data['remarks'] ?? $locked->remarks];
            if ($to === RepairStatus::Completed) {
                $completed = (string) $data['completed_date'];
                if ($completed < $locked->repair_date->toDateString()) {
                    throw ValidationException::withMessages(['completed_date' => 'The completion date cannot be before the repair date.']);
                }
                $values['completed_date'] = $completed;
                if (array_key_exists('cost', $data) && ! blank($data['cost'])) {
                    $values['cost'] = $this->amount($data['cost'], 'cost')->toMoney();
                }
            }
            $locked->forceFill($values)->save();

            if ($equipment->status === EquipmentStatus::UnderRepair) {
                $active = EquipmentAssignment::query()->where('equipment_id', $equipment->id)->where('status', AssignmentStatus::Active)->exists();
                $equipment->forceFill(['status' => $active ? EquipmentStatus::Assigned : EquipmentStatus::Available])->save();
            }
            $repair->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $vendorId = null;
        if (! blank($data['vendor_id'] ?? null)) {
            $vendorId = Vendor::query()->active()->whereKey((int) $data['vendor_id'])->value('id')
                ?? throw ValidationException::withMessages(['vendor_id' => 'Choose an active vendor.']);
        }

        return [
            'repair_date' => $data['repair_date'],
            'description' => $data['description'],
            'vendor_id' => $vendorId,
            'cost' => $this->amount($data['cost'] ?? null, 'cost')->toMoney(),
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}

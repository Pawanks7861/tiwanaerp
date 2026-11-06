<?php

namespace App\Services\Gate;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassItemType;
use App\Enums\Gate\GatePassStatus;
use App\Models\Equipment\Equipment;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassItem;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Equipment\EquipmentLocationService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gate movement register. It does not post stock. An equipment line updates the machine's
 * location only when the pass is gate-verified.
 */
class GatePassService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly WeighbridgeReceiptService $weighbridge,
        private readonly EquipmentLocationService $locations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): GatePass
    {
        return DB::transaction(function () use ($project, $data) {
            $pass = new GatePass;
            $pass->forceFill([
                ...$this->header($project, $data),
                'project_id' => $project->id,
                'pass_number' => $this->numbers->next('gate_pass', $project),
                'status' => GatePassStatus::Draft,
            ])->save();
            $this->replaceItems($pass, $data['items'] ?? []);
            $this->weighbridge->sync($pass, $data['weighbridge'] ?? null);

            return $pass;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GatePass $pass, array $data): GatePass
    {
        return DB::transaction(function () use ($pass, $data) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            $pass->assertEditable();
            $pass->forceFill($this->header($pass->project, $data))->save();
            $this->replaceItems($pass, $data['items'] ?? []);
            $this->weighbridge->sync($pass, $data['weighbridge'] ?? null);

            return $pass;
        });
    }

    public function delete(GatePass $pass): void
    {
        $pass->assertEditable();
        $pass->items()->delete();
        $pass->weighbridgeReceipt()?->delete();
        $pass->delete();
    }

    public function submit(GatePass $pass): void
    {
        $this->transition($pass, [GatePassStatus::Draft, GatePassStatus::Rejected], GatePassStatus::Submitted);
    }

    public function approve(GatePass $pass, User $user): void
    {
        $this->transition($pass, [GatePassStatus::Submitted], GatePassStatus::Approved, [
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(GatePass $pass, string $reason): void
    {
        $this->transition($pass, [GatePassStatus::Submitted], GatePassStatus::Rejected, [
            'rejected_reason' => $reason,
        ]);
    }

    public function verify(GatePass $pass, User $user): void
    {
        DB::transaction(function () use ($pass, $user) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            if ($pass->status !== GatePassStatus::Approved) {
                throw ValidationException::withMessages(['gate_pass' => 'Only an approved gate pass can be verified at the gate.']);
            }
            $pass->forceFill([
                'status' => GatePassStatus::Verified,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ])->save();
            $this->moveEquipment($pass, $user);
            if (! $pass->returnable) {
                $pass->forceFill(['status' => GatePassStatus::Closed])->save();
            }
        });
    }

    public function markReturned(GatePass $pass, User $user): void
    {
        DB::transaction(function () use ($pass, $user) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            if (! $pass->returnable || $pass->direction !== GatePassDirection::Out) {
                throw ValidationException::withMessages(['gate_pass' => 'Only a returnable outward pass can be marked returned.']);
            }
            if (! in_array($pass->status, [GatePassStatus::Verified, GatePassStatus::Approved], true)) {
                throw ValidationException::withMessages(['gate_pass' => 'Verify the outward pass before recording the return.']);
            }
            $pass->forceFill([
                'status' => GatePassStatus::Closed,
                'returned_at' => now(),
            ])->save();
            foreach ($pass->items()->whereNotNull('equipment_id')->get() as $item) {
                $equipment = Equipment::query()->find($item->equipment_id);
                if ($equipment) {
                    $this->locations->change($equipment, [
                        'location_type' => EquipmentLocationType::ProjectSite->value,
                        'location_project_id' => $pass->project_id,
                        'location_name' => $pass->project?->name,
                        'remarks' => "Returned on {$pass->pass_number}",
                    ], $user);
                }
            }
        });
    }

    /**
     * @param  list<GatePassStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(GatePass $pass, array $from, GatePassStatus $to, array $extra = []): void
    {
        DB::transaction(function () use ($pass, $from, $to, $extra) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            if (! in_array($pass->status, $from, true)) {
                throw ValidationException::withMessages(['gate_pass' => 'This gate pass cannot move to '.$to->label().' from '.$pass->status->label().'.']);
            }
            if ($pass->items()->doesntExist()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line.']);
            }
            $pass->forceFill(['status' => $to, ...$extra])->save();
        });
    }

    private function moveEquipment(GatePass $pass, User $user): void
    {
        $outbound = $pass->direction === GatePassDirection::Out;
        foreach ($pass->items()->whereNotNull('equipment_id')->get() as $item) {
            $equipment = Equipment::query()->find($item->equipment_id);
            if ($equipment === null) {
                continue;
            }
            $this->locations->change($equipment, [
                'location_type' => $outbound ? EquipmentLocationType::InTransit->value : EquipmentLocationType::ProjectSite->value,
                'location_project_id' => $outbound ? null : $pass->project_id,
                'location_name' => $outbound ? ($item->destination ?: $pass->party_name ?: $pass->vehicle_no) : $pass->project?->name,
                'remarks' => "Gate pass {$pass->pass_number}",
            ], $user);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        $returnable = (bool) ($data['returnable'] ?? false);
        if ($returnable && blank($data['expected_return_date'] ?? null)) {
            throw ValidationException::withMessages(['expected_return_date' => 'A returnable pass needs an expected return date.']);
        }

        return [
            'site_id' => $data['site_id'] ?? null,
            'direction' => $data['direction'],
            'returnable' => $returnable,
            'passed_at' => $data['passed_at'],
            'expected_return_date' => $returnable ? $data['expected_return_date'] : null,
            'party_name' => $data['party_name'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'driver_mobile' => $data['driver_mobile'] ?? null,
            'purpose' => $data['purpose'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(GatePass $pass, array $rows): void
    {
        $pass->items()->delete();
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }
        foreach (array_values($rows) as $index => $row) {
            $type = GatePassItemType::tryFrom((string) ($row['item_type'] ?? ''))
                ?? throw ValidationException::withMessages(["items.{$index}.item_type" => 'Choose the kind of item.']);
            $qty = Decimal::of((string) ($row['quantity'] ?? '0'));
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be greater than zero.']);
            }
            if ($type === GatePassItemType::Scrap && blank($row['scrap_type'] ?? null)) {
                throw ValidationException::withMessages(["items.{$index}.scrap_type" => 'Name the scrap.']);
            }
            $materialId = blank($row['material_id'] ?? null) ? null : (int) $row['material_id'];
            if ($materialId !== null && Material::query()->whereKey($materialId)->doesntExist()) {
                throw ValidationException::withMessages(["items.{$index}.material_id" => 'Choose a material of this company.']);
            }
            $equipmentId = blank($row['equipment_id'] ?? null) ? null : (int) $row['equipment_id'];
            if ($type === GatePassItemType::Equipment && $equipmentId === null) {
                throw ValidationException::withMessages(["items.{$index}.equipment_id" => 'Choose the equipment.']);
            }
            if ($equipmentId !== null && Equipment::query()->whereKey($equipmentId)->doesntExist()) {
                throw ValidationException::withMessages(["items.{$index}.equipment_id" => 'Choose equipment of this company.']);
            }

            (new GatePassItem)->forceFill([
                'gate_pass_id' => $pass->id,
                'item_type' => $type,
                'material_id' => $materialId,
                'equipment_id' => $equipmentId,
                'description' => $row['description'],
                'quantity' => $qty->toQuantity(),
                'unit_id' => $row['unit_id'] ?? null,
                'weight' => blank($row['weight'] ?? null) ? null : Decimal::of((string) $row['weight'])->toQuantity(),
                'scrap_type' => $row['scrap_type'] ?? null,
                'rate' => blank($row['rate'] ?? null) ? null : Decimal::of((string) $row['rate'])->toRate(),
                'destination' => $row['destination'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'sort_order' => $index + 1,
            ])->save();
        }
    }
}

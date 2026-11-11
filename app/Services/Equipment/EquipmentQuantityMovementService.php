<?php

namespace App\Services\Equipment;

use App\Enums\Equipment\EquipmentQuantityMovementType;
use App\Enums\Equipment\EquipmentTrackingMode;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentQuantityMovement;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ledger for quantity-tracked equipment (tools/fleet units without unique serials).
 * Individual assets never use this path.
 */
class EquipmentQuantityMovementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Equipment $equipment, array $data, User $user): EquipmentQuantityMovement
    {
        return DB::transaction(function () use ($equipment, $data, $user) {
            $locked = Equipment::query()->whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            $mode = $locked->tracking_mode instanceof EquipmentTrackingMode
                ? $locked->tracking_mode
                : EquipmentTrackingMode::tryFrom((string) ($locked->getAttribute('tracking_mode') ?? 'individual'));
            if ($mode !== EquipmentTrackingMode::Quantity) {
                throw ValidationException::withMessages([
                    'equipment' => 'Quantity movements apply only to quantity-tracked equipment.',
                ]);
            }

            $type = EquipmentQuantityMovementType::from((string) $data['movement_type']);
            $qty = Decimal::of((string) ($data['quantity'] ?? '0'))->round(4);
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
            }

            $signed = Decimal::of($type->signedQuantity($qty->toQuantity()));
            $available = Decimal::of((string) ($locked->available_quantity ?? '0'));
            $next = $available->plus($signed);
            if ($next->isNegative()) {
                throw ValidationException::withMessages([
                    'quantity' => 'Available quantity cannot become negative (current '.$available->toQuantity().').',
                ]);
            }

            $movement = new EquipmentQuantityMovement;
            $movement->forceFill([
                'equipment_id' => $locked->id,
                'moved_at' => (string) $data['moved_at'],
                'movement_type' => $type->value,
                'quantity' => $qty->toQuantity(),
                'project_id' => $data['project_id'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $user->id,
            ])->save();

            $locked->forceFill(['available_quantity' => $next->toQuantity()])->save();
            $locked->writeAudit('quantity_moved', null, [
                'movement_id' => $movement->id,
                'movement_type' => $type->value,
                'quantity' => $qty->toQuantity(),
                'available_quantity' => $next->toQuantity(),
            ]);

            return $movement;
        });
    }

    public function recalculateAvailable(Equipment $equipment): string
    {
        $opening = Decimal::of((string) ($equipment->opening_quantity ?? '0'));
        $signed = Decimal::zero();
        foreach ($equipment->quantityMovements()->orderBy('id')->get() as $row) {
            /** @var EquipmentQuantityMovement $row */
            if ($row->movement_type === EquipmentQuantityMovementType::Opening) {
                continue;
            }
            $signed = $signed->plus($row->movement_type->signedQuantity((string) $row->quantity));
        }

        // Opening quantity column is the baseline; ledger movements adjust it.
        $available = $opening->plus($signed);
        if ($available->isNegative()) {
            $available = Decimal::zero();
        }

        $equipment->forceFill(['available_quantity' => $available->toQuantity()])->save();

        return $available->toQuantity();
    }
}

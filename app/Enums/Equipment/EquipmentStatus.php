<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

/**
 * Availability of an equipment asset. assigned / under_repair are set only by the assignment and
 * repair services; disposed is set in the register and never overwritten by them.
 */
enum EquipmentStatus: string
{
    use HasOptions;

    case Available = 'available';
    case Assigned = 'assigned';
    case UnderRepair = 'under_repair';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::UnderRepair => 'Under repair',
            default => ucfirst($this->value),
        };
    }
}

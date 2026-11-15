<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BudgetLineSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case BoqCostHead = 'boq_cost_head';
    case BoqMaterial = 'boq_material';
    case BoqLabour = 'boq_labour';
    case BoqEquipment = 'boq_equipment';
    case BoqOther = 'boq_other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::BoqCostHead => 'From BOQ cost head',
            self::BoqMaterial => 'Materials from BOQ',
            self::BoqLabour => 'Labour from BOQ',
            self::BoqEquipment => 'Equipment from BOQ',
            self::BoqOther => 'Other from BOQ',
        };
    }

    /** Detail lines replaced by Sync from BOQ (not cost-head rollups). */
    public static function syncOwnedValues(): array
    {
        return [
            self::BoqMaterial->value,
            self::BoqLabour->value,
            self::BoqEquipment->value,
            self::BoqOther->value,
        ];
    }
}

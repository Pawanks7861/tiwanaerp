<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum CostComponentType: string
{
    use HasOptions;

    case Material = 'material';
    case Labour = 'labour';
    case Equipment = 'equipment';
    case Subcontract = 'subcontract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Labour => 'Labour',
            self::Equipment => 'Equipment',
            self::Subcontract => 'Subcontract',
            self::Other => 'Other',
        };
    }

    /** Maps to BOQ item rate columns for snapshot sync. */
    public function boqRateField(): string
    {
        return match ($this) {
            self::Material => 'material_rate',
            self::Labour => 'labour_rate',
            self::Equipment => 'equipment_rate',
            self::Subcontract, self::Other => 'subcontract_rate',
        };
    }
}

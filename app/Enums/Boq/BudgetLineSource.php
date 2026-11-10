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

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::BoqCostHead => 'From BOQ cost head',
            self::BoqMaterial => 'Materials from BOQ',
            self::BoqLabour => 'Labour from BOQ',
        };
    }
}

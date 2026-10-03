<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BudgetSource: string
{
    use HasOptions;

    case Boq = 'boq';
    case Manual = 'manual';

    public function label(): string
    {
        return $this === self::Boq ? 'From BOQ' : 'Manual';
    }
}

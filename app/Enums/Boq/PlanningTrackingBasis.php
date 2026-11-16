<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum PlanningTrackingBasis: string
{
    use HasOptions;

    case Nos = 'nos';
    case BoqQuantity = 'boq_quantity';

    public function label(): string
    {
        return match ($this) {
            self::Nos => 'Nos',
            self::BoqQuantity => 'BOQ Quantity',
        };
    }
}

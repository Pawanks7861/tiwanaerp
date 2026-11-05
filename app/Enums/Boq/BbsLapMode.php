<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BbsLapMode: string
{
    use HasOptions;

    case Manual = 'manual';
    case DiameterFactor = 'diameter_factor';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual lap length',
            self::DiameterFactor => 'Diameter × Factor / 1000',
        };
    }
}

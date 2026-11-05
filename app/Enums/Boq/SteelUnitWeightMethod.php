<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum SteelUnitWeightMethod: string
{
    use HasOptions;

    case FormulaD2162 = 'formula_d2_162';
    case Profile = 'profile';

    public function label(): string
    {
        return match ($this) {
            self::FormulaD2162 => 'Formula d² / 162',
            self::Profile => 'Configured unit-weight profile',
        };
    }
}

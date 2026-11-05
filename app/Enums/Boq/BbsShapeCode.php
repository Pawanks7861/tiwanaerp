<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BbsShapeCode: string
{
    use HasOptions;

    case Straight = 'straight';
    case LBar = 'l_bar';
    case UBar = 'u_bar';
    case Stirrup = 'stirrup';
    case Cranked = 'cranked';
    case CustomWhitelisted = 'custom_whitelisted';

    public function label(): string
    {
        return match ($this) {
            self::Straight => 'Straight',
            self::LBar => 'L Bar',
            self::UBar => 'U Bar',
            self::Stirrup => 'Rectangular Stirrup',
            self::Cranked => 'Cranked / Bent',
            self::CustomWhitelisted => 'Custom (A+B+C+D+E)',
        };
    }

    /** Dimension keys required (> 0) for the shape. */
    public function requiredDimensions(): array
    {
        return match ($this) {
            self::Straight => ['a_length'],
            self::LBar => ['a_length', 'b_length'],
            self::UBar => ['a_length', 'b_length'],
            self::Stirrup => ['a_length', 'b_length'],
            self::Cranked => ['a_length', 'b_length', 'c_length', 'd_length'],
            self::CustomWhitelisted => ['a_length'],
        };
    }
}

<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum CircleConstantMode: string
{
    use HasOptions;

    case MathematicalPi = 'mathematical_pi';
    case Legacy314 = 'legacy_3_14';
    case Fraction227 = 'fraction_22_7';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::MathematicalPi => 'Mathematical π',
            self::Legacy314 => 'Legacy 3.14',
            self::Fraction227 => 'Fraction 22/7',
            self::Custom => 'Custom Constant',
        };
    }
}

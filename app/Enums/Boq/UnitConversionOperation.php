<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum UnitConversionOperation: string
{
    use HasOptions;

    case Multiply = 'multiply';
    case Divide = 'divide';

    public function label(): string
    {
        return match ($this) {
            self::Multiply => 'Multiply',
            self::Divide => 'Divide',
        };
    }
}

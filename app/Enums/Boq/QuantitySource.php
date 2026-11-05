<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum QuantitySource: string
{
    use HasOptions;

    case Direct = 'direct';
    case Measurement = 'measurement';
    case Takeoff = 'takeoff';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct entry',
            self::Measurement => 'Measurement sheet',
            self::Takeoff => 'Takeoff',
        };
    }
}

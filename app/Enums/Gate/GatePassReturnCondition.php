<?php

namespace App\Enums\Gate;

use App\Enums\Concerns\HasOptions;

enum GatePassReturnCondition: string
{
    use HasOptions;

    case Good = 'good';
    case Damaged = 'damaged';
    case PartiallyDamaged = 'partially_damaged';
    case Lost = 'lost';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Good',
            self::Damaged => 'Damaged',
            self::PartiallyDamaged => 'Partially damaged',
            self::Lost => 'Lost',
            self::Other => 'Other',
        };
    }
}

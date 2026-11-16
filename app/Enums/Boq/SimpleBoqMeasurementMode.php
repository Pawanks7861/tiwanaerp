<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum SimpleBoqMeasurementMode: string
{
    use HasOptions;

    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual quantity',
            self::Auto => 'Auto measurement',
        };
    }
}

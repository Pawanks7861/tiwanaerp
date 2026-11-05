<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum MeasurementFormulaType: string
{
    use HasOptions;

    case Number = 'number';
    case Length = 'length';
    case Area = 'area';
    case Volume = 'volume';
    case CircleArea = 'circle_area';
    case CircularVolume = 'circular_volume';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'Number (Nos)',
            self::Length => 'Length (Nos × L)',
            self::Area => 'Area (Nos × L × W)',
            self::Volume => 'Volume (Nos × L × W × H)',
            self::CircleArea => 'Circle area (π × r² × Nos)',
            self::CircularVolume => 'Circular volume (π × r² × H × Nos)',
            self::Manual => 'Manual quantity',
        };
    }
}

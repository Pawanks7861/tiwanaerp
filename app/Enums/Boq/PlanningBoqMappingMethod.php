<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum PlanningBoqMappingMethod: string
{
    use HasOptions;

    case ConversionBased = 'conversion_based';
    case ActualMeasurement = 'actual_measurement';
    case ManualCertification = 'manual_certification';

    public function label(): string
    {
        return match ($this) {
            self::ConversionBased => 'Conversion based',
            self::ActualMeasurement => 'Actual measurement',
            self::ManualCertification => 'Manual certification',
        };
    }
}

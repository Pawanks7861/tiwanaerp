<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

/**
 * Usage cost rule: hourly = working hours × rate; daily = one day's rate per usage log (the
 * equipment was on site that day), whatever the hours.
 */
enum RateBasis: string
{
    use HasOptions;

    case Hourly = 'hourly';
    case Daily = 'daily';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

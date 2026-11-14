<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum RateComparisonGstMode: string
{
    use HasOptions;

    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';

    public function label(): string
    {
        return match ($this) {
            self::Inclusive => 'Including GST',
            self::Exclusive => 'Excluding GST',
        };
    }
}

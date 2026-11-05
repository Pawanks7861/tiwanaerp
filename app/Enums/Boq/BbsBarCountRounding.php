<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BbsBarCountRounding: string
{
    use HasOptions;

    case Ceil = 'ceil';
    case Floor = 'floor';
    case Round = 'round';
    case ExactIfInteger = 'exact_if_integer';

    public function label(): string
    {
        return match ($this) {
            self::Ceil => 'Ceil',
            self::Floor => 'Floor',
            self::Round => 'Round (HALF_UP)',
            self::ExactIfInteger => 'Exact if integer (else reject)',
        };
    }
}

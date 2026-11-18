<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum MaterialRequestSourceType: string
{
    use HasOptions;

    case Boq = 'boq';
    case Drawing = 'drawing';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Boq => 'BOQ',
            self::Drawing => 'Drawing',
            self::General => 'General / Unplanned',
        };
    }
}

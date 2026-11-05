<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BbsCountMode: string
{
    use HasOptions;

    case Manual = 'manual';
    case SpacingBased = 'spacing_based';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual (Members × Bars/Member)',
            self::SpacingBased => 'Spacing-based',
        };
    }
}

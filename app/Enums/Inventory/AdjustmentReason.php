<?php

namespace App\Enums\Inventory;

use App\Enums\Concerns\HasOptions;

enum AdjustmentReason: string
{
    use HasOptions;

    case Damage = 'damage';
    case Theft = 'theft';
    case CountCorrection = 'count_correction';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Damage => 'Damage',
            self::Theft => 'Theft / loss',
            self::CountCorrection => 'Physical count correction',
            self::Opening => 'Opening stock',
        };
    }

    /** Damage and theft can only reduce stock. */
    public function isLossOnly(): bool
    {
        return $this === self::Damage || $this === self::Theft;
    }
}

<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum TaxType: string
{
    use HasOptions;

    case Intra = 'intra';
    case Inter = 'inter';

    public function label(): string
    {
        return match ($this) {
            self::Intra => 'Intra-state (CGST + SGST)',
            self::Inter => 'Inter-state (IGST)',
        };
    }
}

<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum GrnExtraReason: string
{
    use HasOptions;

    case SupplierExcess = 'supplier_excess';
    case Packaging = 'packaging';
    case SiteInstruction = 'site_instruction';
    case Measurement = 'measurement';
    case Weight = 'weight';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SupplierExcess => 'Supplier excess delivery',
            self::Packaging => 'Packaging variation',
            self::SiteInstruction => 'Site instruction',
            self::Measurement => 'Measurement difference',
            self::Weight => 'Weight variation',
            self::Other => 'Other',
        };
    }
}

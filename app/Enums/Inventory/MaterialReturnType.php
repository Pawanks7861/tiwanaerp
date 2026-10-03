<?php

namespace App\Enums\Inventory;

use App\Enums\Concerns\HasOptions;

enum MaterialReturnType: string
{
    use HasOptions;

    case SiteToStore = 'site_to_store';
    case ToVendor = 'to_vendor';

    public function label(): string
    {
        return match ($this) {
            self::SiteToStore => 'Site to store',
            self::ToVendor => 'Return to vendor',
        };
    }
}

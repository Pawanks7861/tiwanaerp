<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum WarehouseType: string
{
    use HasOptions;

    case Central = 'central';
    case Site = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Central => 'Central Store',
            self::Site => 'Site Store',
        };
    }
}

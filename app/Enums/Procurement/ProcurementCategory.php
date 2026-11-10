<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum ProcurementCategory: string
{
    use HasOptions;

    case Material = 'material';
    case Equipment = 'equipment';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Equipment => 'Equipment',
        };
    }
}

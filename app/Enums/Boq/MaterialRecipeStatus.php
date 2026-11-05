<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum MaterialRecipeStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}

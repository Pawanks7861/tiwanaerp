<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ItemType: string
{
    use HasOptions;

    case Material = 'material';
    case Consumable = 'consumable';
    case Asset = 'asset';
    case Service = 'service';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

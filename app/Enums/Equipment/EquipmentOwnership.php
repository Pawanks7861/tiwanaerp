<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

enum EquipmentOwnership: string
{
    use HasOptions;

    case Owned = 'owned';
    case Hired = 'hired';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

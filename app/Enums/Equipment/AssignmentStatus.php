<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

enum AssignmentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Returned = 'returned';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

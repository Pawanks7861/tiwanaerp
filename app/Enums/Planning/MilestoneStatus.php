<?php

namespace App\Enums\Planning;

use App\Enums\Concerns\HasOptions;

enum MilestoneStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

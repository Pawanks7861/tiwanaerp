<?php

namespace App\Enums\Subcontract;

use App\Enums\Concerns\HasOptions;

enum MilestoneStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Achieved = 'achieved';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

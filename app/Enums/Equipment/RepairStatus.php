<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

/**
 * open = the equipment is in the workshop (status under_repair); completed / cancelled give it
 * back (assigned when an assignment is still active, otherwise available; disposed stays).
 */
enum RepairStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

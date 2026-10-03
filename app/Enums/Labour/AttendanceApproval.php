<?php

namespace App\Enums\Labour;

use App\Enums\Concerns\HasOptions;

/**
 * Attendance approval state (architecture I.1): marked by the site, approved by the manager.
 * Approval posts the labour cost.
 */
enum AttendanceApproval: string
{
    use HasOptions;

    case Marked = 'marked';
    case Approved = 'approved';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

<?php

namespace App\Enums\Quality;

use App\Enums\Concerns\HasOptions;

/**
 * open → in_progress → resolved → verified → closed. A resolution that fails verification goes
 * back to in_progress. A closed NCR is immutable.
 */
enum NcrStatus: string
{
    use HasOptions;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Verified = 'verified';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }
}

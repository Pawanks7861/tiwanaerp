<?php

namespace App\Enums\Subcontract;

use App\Enums\Concerns\HasOptions;

enum WorkOrderStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Bills can be raised against an approved work order until it is closed or cancelled. */
    public function isBillable(): bool
    {
        return in_array($this, [self::Approved, self::InProgress, self::Completed], true);
    }
}

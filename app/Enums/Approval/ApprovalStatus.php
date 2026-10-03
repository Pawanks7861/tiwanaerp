<?php

namespace App\Enums\Approval;

use App\Enums\Concerns\HasOptions;

enum ApprovalStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case SentBack = 'sent_back';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::SentBack => 'Sent Back',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}

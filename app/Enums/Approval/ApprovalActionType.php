<?php

namespace App\Enums\Approval;

use App\Enums\Concerns\HasOptions;

enum ApprovalActionType: string
{
    use HasOptions;

    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case SentBack = 'sent_back';
    case Cancelled = 'cancelled';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::SentBack => 'Sent Back',
            self::Cancelled => 'Cancelled',
            self::ChangesRequested => 'Changes Requested',
        };
    }
}

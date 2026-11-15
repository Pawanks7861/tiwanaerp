<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum RevisionChangeOrderStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}

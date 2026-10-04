<?php

namespace App\Enums\Documents;

use App\Enums\Concerns\HasOptions;

/**
 * draft → submitted → under_review → approved / rejected. Approving a revision supersedes the
 * previously approved one. Approved and superseded revisions never change.
 */
enum DrawingRevisionStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::UnderReview => 'Under review',
            default => ucfirst($this->value),
        };
    }

    /** Still moving through the workflow (blocks uploading another revision). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::UnderReview], true);
    }
}

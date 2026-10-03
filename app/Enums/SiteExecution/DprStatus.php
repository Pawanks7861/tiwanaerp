<?php

namespace App\Enums\SiteExecution;

use App\Enums\Concerns\HasOptions;

/**
 * DPR lifecycle through the approval engine. 'approved' means progress was posted; correcting an
 * approved DPR reopens it to draft after reversing its progress entries.
 */
enum DprStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved (posted)',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}

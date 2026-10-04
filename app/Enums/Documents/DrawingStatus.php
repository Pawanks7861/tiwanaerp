<?php

namespace App\Enums\Documents;

use App\Enums\Concerns\HasOptions;

/**
 * Drawing header: draft until its first revision is approved, then approved (current_revision_id
 * set). The per-revision workflow lives on DrawingRevisionStatus.
 */
enum DrawingStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'No approved revision',
            self::Approved => 'Approved',
        };
    }
}

<?php

namespace App\Enums\SiteExecution;

use App\Enums\Concerns\HasOptions;

/**
 * Site diary lifecycle: draft → submitted → reviewed → approved, or rejected from submitted /
 * reviewed (rejected diaries are editable again). Changed only by SiteDiaryService.
 */
enum SiteDiaryStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}

<?php

namespace App\Enums\Approval;

use App\Enums\Concerns\HasOptions;

enum ApprovalMode: string
{
    use HasOptions;

    /** The first eligible approver's approval completes the level. */
    case Any = 'any';

    /** Every eligible approver resolved for the level must approve. */
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any one approver',
            self::All => 'All approvers',
        };
    }
}

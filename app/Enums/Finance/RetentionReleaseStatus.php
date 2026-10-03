<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * draft → submitted (engine: PM → Director) → approved. An approved release makes that much of
 * the bill's retention due again; the cash itself moves through a receipt or payment.
 */
enum RetentionReleaseStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
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

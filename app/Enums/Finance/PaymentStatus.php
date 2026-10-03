<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * Only approved payments count: their allocations settle payables and they appear in cash flow.
 * Cancelling an approved payment withdraws its allocations and recomputes every cache.
 */
enum PaymentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}

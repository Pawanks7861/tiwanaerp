<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum PurchaseOrderStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Approved orders (the receipt states are derived from approved GRNs). */
    public function isApprovedOrder(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived, self::Received], true);
    }

    /** Goods may be received against these orders. */
    public function isReceivable(): bool
    {
        return $this === self::Approved || $this === self::PartiallyReceived;
    }

    /** Closed and cancelled orders only count what was actually received. */
    public function isTerminated(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}

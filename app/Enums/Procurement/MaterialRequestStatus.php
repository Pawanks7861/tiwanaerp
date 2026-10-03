<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

/**
 * Users move a request through draft → submitted → approved/rejected. The ordering and receipt
 * states are derived from quantities by ProcurementQuantityService and never set by hand.
 */
enum MaterialRequestStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyOrdered = 'partially_ordered';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Approved requests whose lines may be procured (RFQ / PO). */
    public function isProcurable(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyOrdered, self::Ordered, self::Received], true);
    }
}

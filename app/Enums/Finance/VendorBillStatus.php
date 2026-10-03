<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * Vendor bill: draft → submitted (engine: PM → Director) → approved; partially_paid / paid follow
 * from approved payments.
 */
enum VendorBillStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially paid',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /**
     * @return list<self>
     */
    public static function approvedStates(): array
    {
        return [self::Approved, self::PartiallyPaid, self::Paid];
    }

    public function isApproved(): bool
    {
        return in_array($this, self::approvedStates(), true);
    }
}

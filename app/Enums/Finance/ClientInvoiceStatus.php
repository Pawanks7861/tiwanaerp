<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * Client RA bill: draft → submitted (engine: PM → Director) → certified; partially_paid / paid
 * follow from approved receipts. Certified and later bills are locked.
 */
enum ClientInvoiceStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Certified = 'certified';
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
    public static function certifiedStates(): array
    {
        return [self::Certified, self::PartiallyPaid, self::Paid];
    }

    public function isCertified(): bool
    {
        return in_array($this, self::certifiedStates(), true);
    }

    /**
     * @return list<self>
     */
    public static function openStates(): array
    {
        return [self::Draft, self::Submitted, self::Rejected];
    }
}

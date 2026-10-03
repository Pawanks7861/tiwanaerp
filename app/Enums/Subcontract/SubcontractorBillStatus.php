<?php

namespace App\Enums\Subcontract;

use App\Enums\Concerns\HasOptions;

/**
 * Phase 6 owns draft → submitted → certified (and rejected). partially_paid / paid are set by
 * Phase 7 payments; certified and later statuses count as certified quantity.
 */
enum SubcontractorBillStatus: string
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
}

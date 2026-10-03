<?php

namespace App\Enums\Labour;

use App\Enums\Concerns\HasOptions;

/**
 * Labour payment batch. Payment settles the wage liability; the labour cost was already posted
 * when the attendance was approved, so no status here writes the cost ledger.
 */
enum LabourPaymentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Statuses whose advance recoveries count as recovered. */
    public static function settled(): array
    {
        return [self::Approved, self::Paid];
    }
}

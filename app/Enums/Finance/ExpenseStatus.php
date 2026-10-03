<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * draft → submitted (engine) → approved → paid. Approval posts the project cost; payment only
 * records the cash-out. Petty cash expenses are paid from the float at approval.
 */
enum ExpenseStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
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
        return [self::Approved, self::Paid];
    }
}

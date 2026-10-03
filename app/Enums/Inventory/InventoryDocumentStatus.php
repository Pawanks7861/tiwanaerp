<?php

namespace App\Enums\Inventory;

use App\Enums\Concerns\HasOptions;

/**
 * Lifecycle of material issues, material returns and stock adjustments. 'approved' means posted
 * to the stock ledger; a posted document is cancelled only through compensating reversals.
 */
enum InventoryDocumentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved (posted)',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}

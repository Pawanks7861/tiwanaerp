<?php

namespace App\Enums\Inventory;

use App\Enums\Concerns\HasOptions;

enum StockTransferStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    /** Partially received; the undelivered remainder was written back to the source warehouse. */
    case ClosedShort = 'closed_short';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyReceived => 'Partially received',
            self::ClosedShort => 'Closed short',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isInTransit(): bool
    {
        return $this === self::Dispatched || $this === self::PartiallyReceived;
    }
}

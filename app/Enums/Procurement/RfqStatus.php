<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum RfqStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case QuotesReceived = 'quotes_received';
    case Evaluated = 'evaluated';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** Open RFQs still reserve their quantities against the material request lines. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::QuotesReceived, self::Evaluated], true);
    }

    /** Quotations may be recorded once the RFQ has gone out and until it is evaluated. */
    public function acceptsQuotations(): bool
    {
        return $this === self::Sent || $this === self::QuotesReceived;
    }
}

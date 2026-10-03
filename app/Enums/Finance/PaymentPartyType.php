<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * Counterparty of a receipt or payment, and the only payable type it may settle.
 * A labour payment batch has many labourers, so the batch itself is the party.
 */
enum PaymentPartyType: string
{
    use HasOptions;

    case Client = 'client';
    case Vendor = 'vendor';
    case Subcontractor = 'subcontractor';
    case LabourPayment = 'labour_payment';

    public function label(): string
    {
        return match ($this) {
            self::LabourPayment => 'Labour payment',
            default => ucfirst($this->value),
        };
    }

    public function direction(): PaymentDirection
    {
        return $this === self::Client ? PaymentDirection::Receipt : PaymentDirection::Payment;
    }

    /** Morph alias of the payable this party settles. */
    public function payableType(): string
    {
        return match ($this) {
            self::Client => 'client_invoice',
            self::Vendor => 'vendor_bill',
            self::Subcontractor => 'subcontractor_bill',
            self::LabourPayment => 'labour_payment',
        };
    }
}

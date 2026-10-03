<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * How money moved. petty_cash is only valid for expenses (paid from a petty cash float).
 */
enum PaymentMode: string
{
    use HasOptions;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Upi = 'upi';
    case Card = 'card';
    case PettyCash = 'petty_cash';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Upi => 'UPI',
            self::PettyCash => 'Petty cash',
            default => ucfirst($this->value),
        };
    }

    /**
     * Modes for receipts and payments (petty cash is expense-only).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function paymentOptions(): array
    {
        return array_values(array_filter(self::options(), fn (array $o) => $o['value'] !== self::PettyCash->value));
    }
}

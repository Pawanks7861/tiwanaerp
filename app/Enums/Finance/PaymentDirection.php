<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

enum PaymentDirection: string
{
    use HasOptions;

    case Receipt = 'receipt';
    case Payment = 'payment';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function numberingType(): string
    {
        return $this === self::Receipt ? 'payment_receipt' : 'payment';
    }
}

<?php

namespace App\Events\Finance;

use App\Models\Finance\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An approved receipt from a client. Outgoing payments do not raise this. */
class PaymentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}

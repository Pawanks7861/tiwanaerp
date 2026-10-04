<?php

namespace App\Events\Finance;

use App\Models\Finance\ClientInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A client RA bill received its tax invoice number. */
class ClientInvoiceCertified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ClientInvoice $invoice) {}
}

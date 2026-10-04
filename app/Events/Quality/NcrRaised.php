<?php

namespace App\Events\Quality;

use App\Models\Quality\Ncr;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A non-conformance report was opened. */
class NcrRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Ncr $ncr) {}
}

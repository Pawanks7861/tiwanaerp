<?php

namespace App\Events\Procurement;

use App\Models\Procurement\Grn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised once when a GRN is finally approved. Phase 4 (inventory) posts stock from this event;
 * Phase 3 only notifies.
 */
class GrnApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Grn $grn) {}
}

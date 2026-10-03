<?php

namespace App\Events\Approval;

use App\Models\Approval\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired for both rejection and send-back; check $request->status. */
class ApprovalRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ApprovalRequest $request, public readonly ?string $comments = null) {}
}

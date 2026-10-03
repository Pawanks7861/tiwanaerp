<?php

namespace App\Events\Approval;

use App\Models\Approval\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A level is now waiting for approvers (fired on submit and on each level advance). */
class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ApprovalRequest $request) {}
}

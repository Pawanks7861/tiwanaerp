<?php

namespace App\Models\Concerns;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasApprovals
{
    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable')->latest('id');
    }

    /**
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function latestApprovalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany();
    }

    public function pendingApprovalRequest(): ?ApprovalRequest
    {
        return $this->approvalRequests()->where('status', ApprovalStatus::Pending)->first();
    }
}

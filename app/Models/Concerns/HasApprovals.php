<?php

namespace App\Models\Concerns;

use App\Enums\Approval\ApprovalActionType;
use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use BackedEnum;
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

    /**
     * User id of the final Approved action on the completing request (R14).
     *
     * Prefer the request passed from ApprovalService. Fall back to the latest Approved
     * request for the document (supports resubmission histories). The actor is the user
     * who recorded the Approved action (a delegate, when acting under delegation).
     */
    public function completingApproverId(?ApprovalRequest $request = null): ?int
    {
        $request ??= $this->approvalRequests()
            ->where('status', ApprovalStatus::Approved)
            ->reorder('id', 'desc')
            ->first();

        if ($request === null) {
            return null;
        }

        $userId = $request->actions()
            ->where('action', ApprovalActionType::Approved)
            ->reorder('id', 'desc')
            ->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * Semantic approval audit when the model uses Auditable (R15). Safe no-op otherwise.
     * Called inside the ApprovalService transaction so rolled-back approvals leave no row.
     *
     * @param  BackedEnum|string|null  $from
     * @param  BackedEnum|string  $to
     */
    protected function recordApprovalAudit(string $event, mixed $from, mixed $to, ?ApprovalRequest $request = null): void
    {
        if (! method_exists($this, 'writeAudit')) {
            return;
        }

        $normalize = static fn (mixed $status): ?string => match (true) {
            $status instanceof BackedEnum => (string) $status->value,
            $status === null => null,
            default => (string) $status,
        };

        $new = array_filter([
            'status' => $normalize($to),
            'approval_request_id' => $request?->getKey(),
        ], static fn ($value) => $value !== null);

        $this->writeAudit($event, ['status' => $normalize($from)], $new);
    }
}

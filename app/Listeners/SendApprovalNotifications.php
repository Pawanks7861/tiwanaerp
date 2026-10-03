<?php

namespace App\Listeners;

use App\Events\Approval\ApprovalCompleted;
use App\Events\Approval\ApprovalRejected;
use App\Events\Approval\ApprovalRequested;
use App\Notifications\Approval\ApprovalDecisionNotification;
use App\Notifications\Approval\ApprovalRequestedNotification;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\Notification;

class SendApprovalNotifications
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function handleApprovalRequested(ApprovalRequested $event): void
    {
        $approvers = $this->approvals->approversForCurrentLevel($event->request)
            ->reject(fn ($user) => $user->id === $event->request->submitted_by && ! config('approvals.allow_self_approval'));

        Notification::send($approvers, new ApprovalRequestedNotification($event->request));
    }

    public function handleApprovalCompleted(ApprovalCompleted $event): void
    {
        $event->request->submitter?->notify(new ApprovalDecisionNotification($event->request));
    }

    public function handleApprovalRejected(ApprovalRejected $event): void
    {
        $event->request->submitter?->notify(new ApprovalDecisionNotification($event->request, $event->comments));
    }
}

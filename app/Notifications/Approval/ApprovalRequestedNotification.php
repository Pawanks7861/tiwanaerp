<?php

namespace App\Notifications\Approval;

use App\Models\Approval\ApprovalRequest;
use App\Notifications\BaseNotification;

class ApprovalRequestedNotification extends BaseNotification
{
    // Captured at dispatch time: queued notifications run outside any company context.
    public readonly string $documentTitle;

    public function __construct(ApprovalRequest $request)
    {
        $this->companyId = $request->company_id;
        $this->documentTitle = $request->approvable?->approvalTitle() ?? 'Document';
    }

    public function preferenceKey(): string
    {
        return 'approval.requested';
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'approval.requested',
            'title' => 'Approval requested',
            'body' => "{$this->documentTitle} is waiting for your approval.",
            'url' => route('approvals.index', absolute: false),
        ];
    }
}

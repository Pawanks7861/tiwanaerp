<?php

namespace App\Notifications\Approval;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use App\Notifications\BaseNotification;

/** Tells the submitter their document was approved, rejected or sent back. */
class ApprovalDecisionNotification extends BaseNotification
{
    // Captured at dispatch time: queued notifications run outside any company context.
    public readonly string $documentTitle;

    public readonly ApprovalStatus $status;

    public function __construct(ApprovalRequest $request, public readonly ?string $comments = null)
    {
        $this->companyId = $request->company_id;
        $this->documentTitle = $request->approvable?->approvalTitle() ?? 'Document';
        $this->status = $request->status;
    }

    public function preferenceKey(): string
    {
        return 'approval.'.$this->status->value;
    }

    protected function payload(object $notifiable): array
    {
        $verb = match ($this->status) {
            ApprovalStatus::Approved => 'approved',
            ApprovalStatus::Rejected => 'rejected',
            ApprovalStatus::SentBack => 'sent back for changes',
            default => $this->status->value,
        };

        return [
            'kind' => 'approval.'.$this->status->value,
            'title' => 'Approval '.$this->status->label(),
            'body' => trim("{$this->documentTitle} was {$verb}. ".($this->comments ?? '')),
            'url' => null,
        ];
    }
}

<?php

namespace App\Notifications\Approval;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialReturn;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\SiteDiary;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\RetentionRelease;
use App\Models\Finance\VendorBill;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Gate\GatePass;
use App\Models\Subcontract\WorkOrder;
use App\Notifications\BaseNotification;
use Illuminate\Database\Eloquent\Model;

/** Tells the submitter their document was approved, rejected or sent back. */
class ApprovalDecisionNotification extends BaseNotification
{
    // Captured at dispatch time: queued notifications run outside any company context.
    public readonly string $documentTitle;

    public readonly ApprovalStatus $status;

    public readonly ?int $projectId;

    public readonly ?string $targetType;

    public readonly ?int $targetId;

    public function __construct(ApprovalRequest $request, public readonly ?string $comments = null)
    {
        $this->companyId = $request->company_id;
        $approvable = $request->approvable;
        $this->documentTitle = $approvable?->approvalTitle() ?? 'Document';
        $this->status = $request->status;
        $this->projectId = $approvable && method_exists($approvable, 'approvalProjectId')
            ? $approvable->approvalProjectId()
            : ($approvable->project_id ?? null);
        [$this->targetType, $this->targetId] = $this->target($approvable);
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
            'project_id' => $this->projectId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'mobile_route' => $this->mobileRoute($this->targetType),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function target(?Model $doc): array
    {
        if ($doc === null) {
            return [null, null];
        }

        $type = match (true) {
            $doc instanceof MaterialRequest => 'material_request',
            $doc instanceof PurchaseOrder => 'purchase_order',
            $doc instanceof Grn => 'grn',
            $doc instanceof MaterialIssue => 'material_issue',
            $doc instanceof MaterialReturn => 'material_return',
            $doc instanceof Dpr => 'dpr',
            $doc instanceof SiteDiary => 'site_diary',
            $doc instanceof ProjectTask => 'task',
            $doc instanceof WorkOrder => 'work_order',
            $doc instanceof SubcontractorBill => 'subcontractor_bill',
            $doc instanceof ClientInvoice => 'client_invoice',
            $doc instanceof VendorBill => 'vendor_bill',
            $doc instanceof Expense => 'expense',
            $doc instanceof RetentionRelease => 'retention_release',
            $doc instanceof GatePass => 'gate_pass',
            default => $doc->getMorphClass(),
        };

        return [$type, (int) $doc->getKey()];
    }

    private function mobileRoute(?string $type): ?string
    {
        return match ($type) {
            'material_request' => 'MaterialRequisitionDetail',
            'purchase_order' => 'PurchaseOrderDetail',
            'grn' => 'GrnDetail',
            'material_issue' => 'MaterialIssueDetail',
            'material_return' => 'MaterialReturnDetail',
            'dpr' => 'DprDetail',
            'site_diary' => 'SiteDiaryDetail',
            'task' => 'TaskDetail',
            'work_order' => 'WorkOrderDetail',
            'subcontractor_bill' => 'SubcontractorBillDetail',
            'client_invoice' => 'ClientRaBillDetail',
            'vendor_bill' => 'VendorBillDetail',
            'expense' => 'ExpenseDetail',
            'retention_release' => 'RetentionReleaseDetail',
            'gate_pass' => 'GatePassDetail',
            default => null,
        };
    }
}

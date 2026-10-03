<?php

namespace App\Models\Finance;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\CostHead;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Finance\VendorBillType;
use App\Enums\Procurement\TaxType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Vendor;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\VendorBillService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor invoice entered against a PO and its approved GRNs (3-way match), or as a direct
 * non-stock / service bill classified with a cost head. Approval runs through the engine
 * (PM → Director, final approver holds vendor_bills.approve).
 */
class VendorBill extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'approved_by', 'approved_at', 'paid_amount', 'updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => VendorBillStatus::class,
            'bill_type' => VendorBillType::class,
            'cost_head' => CostHead::class,
            'tax_type' => TaxType::class,
            'vendor_invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'tds_percent' => 'decimal:4',
            'tds_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This vendor bill is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'bill';
    }

    public function isDirect(): bool
    {
        return $this->bill_type === VendorBillType::Direct;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    /**
     * @return HasMany<VendorBillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VendorBillItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalDocumentType(): string
    {
        return 'vendor_bill';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->total_amount;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->bill_number} - vendor bill";
    }

    public function approvalUrl(): string
    {
        return route('projects.vendor-bills.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => VendorBillStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(VendorBillService::class)->approve($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => VendorBillStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => VendorBillStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => VendorBillStatus::Draft])->save();
    }
}

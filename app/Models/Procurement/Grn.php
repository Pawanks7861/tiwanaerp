<?php

namespace App\Models\Procurement;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Procurement\GrnStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Procurement\GrnService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Goods receipt against a purchase order. Approved GRNs are immutable; they drive the PO and MR
 * received quantities and raise GrnApproved (the inventory boundary for Phase 4).
 */
#[Fillable([
    'warehouse_id', 'receipt_date', 'vendor_invoice_no', 'vendor_invoice_date', 'vendor_challan_no', 'vehicle_no', 'remarks',
])]
class Grn extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'approved_by', 'approved_at', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return [
            'status' => GrnStatus::class,
            'receipt_date' => 'date',
            'vendor_invoice_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This GRN is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'grn';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<GrnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class)->orderBy('id');
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
        return 'grn';
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->grn_number} - goods receipt";
    }

    public function approvalUrl(): string
    {
        return route('projects.grns.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => GrnStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(GrnService::class)->markApproved($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => GrnStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => GrnStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => GrnStatus::Draft])->save();
    }
}

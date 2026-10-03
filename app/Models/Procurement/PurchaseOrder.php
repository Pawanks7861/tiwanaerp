<?php

namespace App\Models\Procurement;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Procurement\PurchaseOrderService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purchase order. tax_type, vendor_state_code and all totals are set by PurchaseOrderService through
 * GstCalculator; approved orders change only through an amendment (revision snapshot + re-approval).
 */
#[Fillable([
    'po_date', 'delivery_date', 'billing_address', 'shipping_address', 'place_of_supply_state',
    'payment_terms', 'terms', 'remarks', 'freight_amount', 'other_charges',
])]
class PurchaseOrder extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'revision_no', 'approved_by', 'approved_at', 'cancelled_reason', 'cancelled_by', 'cancelled_at',
        'updated_by', 'updated_at',
    ];

    public const MONEY_FIELDS = [
        'subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount',
        'freight_amount', 'other_charges', 'round_off', 'grand_total',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'tax_type' => TaxType::class,
            'revision_no' => 'integer',
            'po_date' => 'date',
            'delivery_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            ...array_fill_keys(self::MONEY_FIELDS, 'decimal:2'),
        ];
    }

    public function lockedMessage(): string
    {
        return 'This purchase order is submitted or approved and can no longer be changed. Use Amend to revise an approved order.';
    }

    public function lockKey(): string
    {
        return 'purchase_order';
    }

    public function isDirect(): bool
    {
        return $this->rfq_id === null;
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
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<VendorQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class, 'vendor_quotation_id');
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PurchaseOrderRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PurchaseOrderRevision::class)->orderByDesc('revision_no');
    }

    /**
     * @return HasMany<Grn, $this>
     */
    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvalDocumentType(): string
    {
        return 'purchase_order';
    }

    public function approvalAmount(): ?string
    {
        return $this->grand_total;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return $this->po_number.($this->revision_no > 0 ? " (Rev {$this->revision_no})" : '').' - purchase order';
    }

    public function approvalUrl(): string
    {
        return route('projects.purchase-orders.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => PurchaseOrderStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(PurchaseOrderService::class)->markApproved($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => PurchaseOrderStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => PurchaseOrderStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => PurchaseOrderStatus::Draft])->save();
    }
}

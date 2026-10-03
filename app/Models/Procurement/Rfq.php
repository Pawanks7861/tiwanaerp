<?php

namespace App\Models\Procurement;

use App\Enums\Procurement\RfqStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Request for quotation. Items and header change only in draft; vendors may be added until the
 * RFQ is evaluated. Status changes only through RfqService and BidComparisonService.
 */
#[Fillable(['title', 'rfq_date', 'due_date', 'required_date', 'terms'])]
class Rfq extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'sent_at', 'closed_at', 'cancelled_reason', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return [
            'status' => RfqStatus::class,
            'rfq_date' => 'date',
            'due_date' => 'date',
            'required_date' => 'date',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === RfqStatus::Draft;
    }

    protected function wasEditable(): bool
    {
        $original = $this->getOriginal('status');

        return $original === null || $original === RfqStatus::Draft;
    }

    public function lockedMessage(): string
    {
        return 'This RFQ has been sent and its items can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'rfq';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<RfqItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<RfqVendor, $this>
     */
    public function vendors(): HasMany
    {
        return $this->hasMany(RfqVendor::class)->orderBy('id');
    }

    /**
     * @return HasMany<VendorQuotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(VendorQuotation::class)->orderBy('id');
    }

    /**
     * @return HasOne<BidComparison, $this>
     */
    public function comparison(): HasOne
    {
        return $this->hasOne(BidComparison::class);
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}

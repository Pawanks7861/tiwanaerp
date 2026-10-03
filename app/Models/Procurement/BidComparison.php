<?php

namespace App\Models\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\SelectionBasis;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evaluation of an RFQ's quotations. The user selects the vendor and records why; the system
 * never auto-selects. Submitted and approved comparisons are locked.
 */
class BidComparison extends Model
{
    use Auditable, BelongsToCompany, Blameable, LocksWhenNotEditable;

    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejection_reason', 'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => BidComparisonStatus::class,
            'selection_basis' => SelectionBasis::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This bid comparison is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'comparison';
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
    public function selectedQuotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class, 'selected_vendor_quotation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

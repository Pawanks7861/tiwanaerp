<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\AdjustmentReason;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stock adjustment (damage, theft, physical count correction, opening stock). Approval by a
 * holder of inventory.approve_adjustment other than the submitter posts adjustment_in / _out.
 */
#[Fillable(['warehouse_id', 'adjustment_date', 'reason', 'remarks'])]
class StockAdjustment extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejection_reason',
        'cancelled_by', 'cancelled_at', 'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventoryDocumentStatus::class,
            'reason' => AdjustmentReason::class,
            'adjustment_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This stock adjustment is submitted or posted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'adjustment';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockTransferStatus;
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
 * Warehouse-to-warehouse transfer. Dispatch posts transfer_out at the source average cost; each
 * receipt posts transfer_in at that captured cost. Between the two the goods are in transit.
 */
#[Fillable(['transfer_date', 'from_warehouse_id', 'to_warehouse_id', 'vehicle_no', 'remarks'])]
class StockTransfer extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'dispatched_by', 'dispatched_at', 'completed_at', 'closed_by', 'closed_at', 'close_reason',
        'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'transfer_date' => 'date',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This transfer has been dispatched and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'transfer';
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
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<StockTransferReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}

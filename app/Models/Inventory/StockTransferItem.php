<?php

namespace App\Models\Inventory;

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer line. Cost and the received / short-closed caches are written by StockTransferService.
 */
class StockTransferItem extends Model
{
    public const POSTING_COLUMNS = ['unit_cost', 'value', 'received_qty', 'received_value', 'short_closed_qty', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $parent = fn (self $item) => StockTransfer::query()->withoutGlobalScopes()->findOrFail($item->stock_transfer_id);

        static::saving(function (self $item) use ($parent) {
            $transfer = $parent($item);
            $postingOnly = $item->exists && array_diff(array_keys($item->getDirty()), self::POSTING_COLUMNS) === [];

            if (! $transfer->isEditable() && ! $postingOnly) {
                throw $transfer->lockedException();
            }
        });

        static::deleting(fn (self $item) => $parent($item)->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
            'received_qty' => 'decimal:4',
            'received_value' => 'decimal:2',
            'short_closed_qty' => 'decimal:4',
        ];
    }

    /** Quantity dispatched but neither received nor closed short. */
    public function inTransitQty(): Decimal
    {
        return Decimal::of($this->quantity)->minus($this->received_qty)->minus($this->short_closed_qty);
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasMany<StockTransferReceiptItem, $this>
     */
    public function receiptItems(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class);
    }
}

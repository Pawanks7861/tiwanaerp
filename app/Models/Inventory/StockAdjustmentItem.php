<?php

namespace App\Models\Inventory;

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adjustment line: difference = physical_qty − system_qty, always computed by the server.
 */
class StockAdjustmentItem extends Model
{
    public const POSTING_COLUMNS = ['unit_cost', 'value', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $parent = fn (self $item) => StockAdjustment::query()->withoutGlobalScopes()->findOrFail($item->stock_adjustment_id);

        static::saving(function (self $item) use ($parent) {
            $adjustment = $parent($item);
            $postingOnly = $item->exists && array_diff(array_keys($item->getDirty()), self::POSTING_COLUMNS) === [];

            if (! $adjustment->isEditable() && ! $postingOnly) {
                throw $adjustment->lockedException();
            }
        });

        static::deleting(fn (self $item) => $parent($item)->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4',
            'physical_qty' => 'decimal:4',
            'difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
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
}

<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\Material;
use App\Models\Masters\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached balance per warehouse + material, derived from stock_transactions. Only
 * StockLedgerService (and the inventory:reconcile --fix repair) may write it.
 */
class StockBalance extends Model
{
    use BelongsToCompany;

    public const CREATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'avg_cost' => 'decimal:4',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}

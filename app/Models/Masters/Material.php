<?php

namespace App\Models\Masters;

use App\Enums\ItemType;
use App\Models\Boq\RateAnalysisItem;
use App\Models\Inventory\StockTransaction;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\RfqItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item master ("Items" in the UI): materials, consumables, assets and services.
 */
#[Fillable([
    'material_category_id', 'item_type', 'code', 'name', 'description', 'hsn_sac', 'unit_id',
    'tax_rate_id', 'reorder_level', 'standard_rate', 'is_active',
])]
class Material extends MasterModel
{
    protected array $searchable = ['name', 'code', 'hsn_sac'];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'reorder_level' => 'decimal:4',
            'standard_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return RateAnalysisItem::query()->where('material_id', $this->id)->exists()
            || MaterialRequestItem::query()->where('material_id', $this->id)->exists()
            || RfqItem::query()->where('material_id', $this->id)->exists()
            || PurchaseOrderItem::query()->where('material_id', $this->id)->exists()
            || StockTransaction::query()->where('material_id', $this->id)->exists();
    }

    /**
     * @return BelongsTo<MaterialCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}

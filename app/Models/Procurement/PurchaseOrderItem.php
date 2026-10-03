<?php

namespace App\Models\Procurement;

use App\Models\Masters\Material;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order line. Amounts come from GstCalculator; received_qty is a cache recomputed from
 * approved GRNs by ProcurementQuantityService.
 */
class PurchaseOrderItem extends Model
{
    public const CACHE_COLUMNS = ['received_qty', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->exists && array_diff(array_keys($item->getDirty()), self::CACHE_COLUMNS) === []) {
                return;
            }
            $item->parentOrder()->assertEditable();
        });

        static::deleting(fn (self $item) => $item->parentOrder()->assertEditable());
    }

    private function parentOrder(): PurchaseOrder
    {
        return $this->relationLoaded('purchaseOrder') && $this->purchaseOrder?->id === $this->purchase_order_id
            ? $this->purchaseOrder
            : PurchaseOrder::query()->withoutGlobalScopes()->findOrFail($this->purchase_order_id);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst_rate' => 'decimal:4',
            'cgst_amount' => 'decimal:2',
            'sgst_rate' => 'decimal:4',
            'sgst_amount' => 'decimal:2',
            'igst_rate' => 'decimal:4',
            'igst_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'received_qty' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<MaterialRequestItem, $this>
     */
    public function materialRequestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class);
    }

    /**
     * @return BelongsTo<VendorQuotationItem, $this>
     */
    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(VendorQuotationItem::class, 'vendor_quotation_item_id');
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
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * @return HasMany<GrnItem, $this>
     */
    public function grnItems(): HasMany
    {
        return $this->hasMany(GrnItem::class);
    }
}

<?php

namespace App\Models\Finance;

use App\Models\Masters\Material;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = fn (self $item) => VendorBill::query()->withoutGlobalScopes()->withTrashed()
            ->findOrFail($item->vendor_bill_id)->assertEditable();

        static::saving($guard);
        static::deleting($guard);
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
        ];
    }

    /**
     * @return BelongsTo<VendorBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id')->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * @return BelongsTo<GrnItem, $this>
     */
    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class)->withTrashed();
    }
}

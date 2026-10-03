<?php

namespace App\Models\Procurement;

use App\Models\Masters\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quotation line. All amounts are computed by VendorQuotationService (never mass assigned).
 */
class VendorQuotationItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            VendorQuotation::query()->withoutGlobalScopes()->findOrFail($item->vendor_quotation_id)->assertEditable();
        };

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
            'tax_percent' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<VendorQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class, 'vendor_quotation_id');
    }

    /**
     * @return BelongsTo<RfqItem, $this>
     */
    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}

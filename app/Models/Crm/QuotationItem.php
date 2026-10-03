<?php

namespace App\Models\Crm;

use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = fn (self $item) => Quotation::query()->withoutGlobalScopes()->withTrashed()
            ->findOrFail($item->quotation_id)->assertEditable();

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
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
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

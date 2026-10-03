<?php

namespace App\Models\Procurement;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * A vendor's offer against an RFQ (one per vendor). Totals are always recomputed on the server.
 * Quotations are frozen once the RFQ is evaluated (comparison submitted or approved).
 */
#[Fillable([
    'quotation_number', 'quotation_date', 'valid_until', 'delivery_days', 'payment_terms', 'warranty',
    'freight_amount', 'other_charges', 'remarks',
])]
class VendorQuotation extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, SoftDeletes;

    private const LIFECYCLE_COLUMNS = ['is_selected', 'updated_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $quotation) {
            if (array_diff(array_keys($quotation->getDirty()), self::LIFECYCLE_COLUMNS) !== []) {
                $quotation->assertEditable();
            }
        });

        static::deleting(fn (self $quotation) => $quotation->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'delivery_days' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'is_selected' => 'boolean',
        ];
    }

    public function parentRfq(): Rfq
    {
        return $this->relationLoaded('rfq') && $this->rfq?->id === $this->rfq_id
            ? $this->rfq
            : Rfq::query()->withoutGlobalScopes()->findOrFail($this->rfq_id);
    }

    public function isEditable(): bool
    {
        return $this->parentRfq()->status->acceptsQuotations();
    }

    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw ValidationException::withMessages([
                'quotation' => 'Quotations can only be changed while the RFQ is sent and not yet evaluated.',
            ]);
        }
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<VendorQuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VendorQuotationItem::class)->orderBy('id');
    }
}

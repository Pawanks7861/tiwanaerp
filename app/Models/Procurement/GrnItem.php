<?php

namespace App\Models\Procurement;

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GRN line: accepted_qty = received_qty − rejected_qty, always computed by GrnService.
 */
class GrnItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            Grn::query()->withoutGlobalScopes()->findOrFail($item->grn_id)->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'previously_received_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rate' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Grn, $this>
     */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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

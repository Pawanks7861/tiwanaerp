<?php

namespace App\Models\Procurement;

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['material_request_item_id', 'material_id', 'unit_id', 'quantity', 'required_date', 'specification', 'sort_order'])]
class RfqItem extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $item) {
            $rfq = $item->relationLoaded('rfq') && $item->rfq?->id === $item->rfq_id
                ? $item->rfq
                : Rfq::query()->withoutGlobalScopes()->findOrFail($item->rfq_id);
            $rfq->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'required_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<MaterialRequestItem, $this>
     */
    public function materialRequestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class);
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

<?php

namespace App\Models\Boq;

use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\VariationChangeType;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqVariationItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'change_type' => VariationChangeType::class,
            'quantity_source' => QuantitySource::class,
            'original_quantity' => 'decimal:4',
            'quantity_change' => 'decimal:4',
            'revised_quantity' => 'decimal:4',
            'original_selling_rate' => 'decimal:4',
            'proposed_selling_rate' => 'decimal:4',
            'original_cost_rate' => 'decimal:4',
            'proposed_cost_rate' => 'decimal:4',
            'gst_rate' => 'decimal:4',
            'revenue_impact' => 'decimal:2',
            'cost_impact' => 'decimal:2',
            'margin_impact' => 'decimal:2',
            'measurement_payload' => 'array',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BoqVariation, $this>
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(BoqVariation::class, 'variation_id');
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'original_boq_item_id');
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function materializedItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'materialized_boq_item_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<BoqSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'boq_section_id');
    }
}

<?php

namespace App\Models\Boq;

use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ line. Derived amounts (cost_rate, cost_amount, selling_rate, client_amount) are always
 * computed by BoqCalculator on the server; line_uid is stable across revisions.
 */
#[Fillable([
    'boq_section_id', 'line_uid', 'item_code', 'name', 'description', 'hsn_sac', 'unit_id', 'quantity',
    'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'cost_rate', 'cost_amount',
    'margin_percent', 'selling_rate', 'client_rate', 'client_amount', 'rate_analysis_id', 'sort_order',
])]
class BoqItem extends Model
{
    /** Internal cost fields, hidden from users without boq.view_costs. */
    public const COST_FIELDS = [
        'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'cost_rate', 'cost_amount',
        'margin_percent', 'selling_rate',
    ];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            $boq = $item->relationLoaded('boq') && $item->boq?->id === $item->boq_id
                ? $item->boq
                : Boq::query()->withoutGlobalScopes()->find($item->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'material_rate' => 'decimal:4',
            'labour_rate' => 'decimal:4',
            'equipment_rate' => 'decimal:4',
            'subcontract_rate' => 'decimal:4',
            'cost_rate' => 'decimal:4',
            'cost_amount' => 'decimal:2',
            'margin_percent' => 'decimal:4',
            'selling_rate' => 'decimal:4',
            'client_rate' => 'decimal:4',
            'client_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    /**
     * @return BelongsTo<BoqSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'boq_section_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<RateAnalysis, $this>
     */
    public function rateAnalysis(): BelongsTo
    {
        return $this->belongsTo(RateAnalysis::class);
    }
}

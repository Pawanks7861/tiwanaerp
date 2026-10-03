<?php

namespace App\Models\Boq;

use App\Enums\Boq\ResourceType;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'resource_type', 'material_id', 'labour_trade_id', 'equipment_type_id', 'description', 'unit_id',
    'quantity', 'wastage_percent', 'rate', 'amount', 'sort_order',
])]
class RateAnalysisItem extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $item) {
            RateAnalysis::query()->withoutGlobalScopes()->find($item->rate_analysis_id)?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'quantity' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RateAnalysis, $this>
     */
    public function rateAnalysis(): BelongsTo
    {
        return $this->belongsTo(RateAnalysis::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<LabourTrade, $this>
     */
    public function labourTrade(): BelongsTo
    {
        return $this->belongsTo(LabourTrade::class);
    }

    /**
     * @return BelongsTo<EquipmentType, $this>
     */
    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}

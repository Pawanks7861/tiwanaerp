<?php

namespace App\Models\Utilities;

use App\Enums\Utilities\ElectricityCalculationBasis;
use App\Enums\Utilities\ElectricityComponentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'electricity_tariff_id', 'component_code', 'component_name', 'component_type', 'calculation_basis',
    'rate', 'percentage', 'slab_config', 'tod_slot_config', 'formula_config', 'unit', 'sort_order',
    'is_rebate', 'is_tax', 'is_penalty', 'is_manual_adjustment', 'is_active',
])]
class ElectricityTariffComponent extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'component_type' => ElectricityComponentType::class,
            'calculation_basis' => ElectricityCalculationBasis::class,
            'rate' => 'decimal:4',
            'percentage' => 'decimal:4',
            'slab_config' => 'array',
            'tod_slot_config' => 'array',
            'formula_config' => 'array',
            'is_rebate' => 'boolean',
            'is_tax' => 'boolean',
            'is_penalty' => 'boolean',
            'is_manual_adjustment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ElectricityTariff, $this> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(ElectricityTariff::class, 'electricity_tariff_id');
    }
}

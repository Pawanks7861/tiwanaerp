<?php

namespace App\Models\Utilities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'electricity_bill_id', 'tariff_component_id', 'component_code', 'component_name', 'calculation_basis',
    'quantity', 'rate', 'percentage', 'calculated_amount', 'actual_amount', 'variance_amount',
    'is_rebate', 'is_tax', 'is_penalty', 'remarks', 'sort_order',
])]
class ElectricityBillComponent extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'percentage' => 'decimal:4',
            'calculated_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'is_rebate' => 'boolean',
            'is_tax' => 'boolean',
            'is_penalty' => 'boolean',
        ];
    }

    /** @return BelongsTo<ElectricityBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(ElectricityBill::class, 'electricity_bill_id');
    }

    /** @return BelongsTo<ElectricityTariffComponent, $this> */
    public function tariffComponent(): BelongsTo
    {
        return $this->belongsTo(ElectricityTariffComponent::class, 'tariff_component_id');
    }
}

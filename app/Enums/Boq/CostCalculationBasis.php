<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum CostCalculationBasis: string
{
    use HasOptions;

    case QuantityRate = 'quantity_rate';
    case BaseRateMultiplyFactor = 'base_rate_multiply_factor';
    case BaseRateDivideFactor = 'base_rate_divide_factor';
    case PercentOfMaterialSubtotal = 'percent_of_material_subtotal';
    case PercentOfLabourSubtotal = 'percent_of_labour_subtotal';
    case PercentOfSubtotal = 'percent_of_subtotal';
    case FixedPerBoqUnit = 'fixed_per_boq_unit';

    public function label(): string
    {
        return match ($this) {
            self::QuantityRate => 'Quantity × Rate',
            self::BaseRateMultiplyFactor => 'Base Rate × Factor',
            self::BaseRateDivideFactor => 'Base Rate ÷ Factor',
            self::PercentOfMaterialSubtotal => '% of Material Subtotal',
            self::PercentOfLabourSubtotal => '% of Labour Subtotal',
            self::PercentOfSubtotal => '% of Subtotal',
            self::FixedPerBoqUnit => 'Fixed per BOQ unit',
        };
    }

    public function isPercentBased(): bool
    {
        return in_array($this, [
            self::PercentOfMaterialSubtotal,
            self::PercentOfLabourSubtotal,
            self::PercentOfSubtotal,
        ], true);
    }

    public function isDerivedRate(): bool
    {
        return in_array($this, [
            self::BaseRateMultiplyFactor,
            self::BaseRateDivideFactor,
        ], true);
    }
}

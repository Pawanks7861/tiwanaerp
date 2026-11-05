<?php

namespace App\Services\Boq;

use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative BOQ cost build-up maths (estimate only).
 *
 * Component cost/BOQ unit = consumption × rate × (1 + wastage%/100)
 */
class CostBuildUpCalculator
{
    public const MAX_WASTAGE_PERCENT = '100';

    /**
     * @param  array{consumption_qty?: string|null, rate?: string|null, wastage_percent?: string|null}  $input
     * @return array{consumption_qty: string, rate: string, wastage_percent: string, amount_per_boq_unit: string}
     */
    public static function component(array $input): array
    {
        $qty = Decimal::of($input['consumption_qty'] ?? '0')->round(Decimal::QTY_SCALE);
        $rate = Decimal::of($input['rate'] ?? '0')->round(Decimal::RATE_SCALE);
        $wastage = Decimal::of($input['wastage_percent'] ?? '0')->round(Decimal::PERCENT_SCALE);

        if ($qty->isNegative() || $rate->isNegative() || $wastage->isNegative()) {
            throw ValidationException::withMessages(['component' => 'Consumption, rate and wastage cannot be negative.']);
        }
        if ($wastage->greaterThan(self::MAX_WASTAGE_PERCENT)) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage cannot exceed 100%.']);
        }

        $base = $qty->times($rate);
        $factor = Decimal::of('1')->plus($wastage->dividedBy(100, 10));
        $amount = $base->times($factor)->round(Decimal::RATE_SCALE);

        return [
            'consumption_qty' => $qty->toQuantity(),
            'rate' => $rate->toRate(),
            'wastage_percent' => $wastage->round(Decimal::PERCENT_SCALE)->toString(),
            'amount_per_boq_unit' => $amount->toRate(),
        ];
    }

    /**
     * @param  iterable<string|null>  $amountsPerUnit
     */
    public static function costRate(iterable $amountsPerUnit): string
    {
        return Decimal::sum($amountsPerUnit)->round(Decimal::RATE_SCALE)->toRate();
    }

    /**
     * @return array{
     *   cost_rate: string,
     *   estimated_cost: string,
     *   selling_rate: string,
     *   estimated_revenue: string,
     *   margin_per_unit: string,
     *   estimated_margin: string,
     *   margin_percent: string|null
     * }
     */
    public static function summary(string $boqQty, string $costRate, string $sellingRate): array
    {
        $qty = Decimal::of($boqQty)->round(Decimal::QTY_SCALE);
        $cost = Decimal::of($costRate)->round(Decimal::RATE_SCALE);
        $sell = Decimal::of($sellingRate)->round(Decimal::RATE_SCALE);

        $estimatedCost = $qty->times($cost)->toMoney();
        $revenue = $qty->times($sell)->toMoney();
        $marginUnit = $sell->minus($cost)->round(Decimal::RATE_SCALE);
        $estimatedMargin = $qty->times($marginUnit)->toMoney();

        $marginPercent = null;
        if ($sell->isPositive()) {
            $marginPercent = $marginUnit->times(100)->dividedBy($sell, Decimal::PERCENT_SCALE)->toString();
        }

        return [
            'cost_rate' => $cost->toRate(),
            'estimated_cost' => $estimatedCost,
            'selling_rate' => $sell->toRate(),
            'estimated_revenue' => $revenue,
            'margin_per_unit' => $marginUnit->toRate(),
            'estimated_margin' => $estimatedMargin,
            'margin_percent' => $marginPercent,
        ];
    }
}

<?php

namespace App\Services\Boq;

use App\Enums\Boq\CostCalculationBasis;
use App\Enums\Boq\CostComponentType;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative BOQ cost build-up maths (estimate only).
 *
 * Stage 3: Component = consumption × rate × (1 + wastage%/100)
 * Stage 9A: Whitelisted calculation_basis modes + deterministic dependency order.
 */
class CostBuildUpCalculator
{
    public const MAX_WASTAGE_PERCENT = '100';

    /**
     * @param  array{
     *   calculation_basis?: string|null,
     *   consumption_qty?: string|null,
     *   rate?: string|null,
     *   base_rate?: string|null,
     *   factor?: string|null,
     *   factor_reference?: string|null,
     *   percent_value?: string|null,
     *   wastage_percent?: string|null,
     *   amount_per_boq_unit?: string|null,
     *   material_subtotal?: string|null,
     *   labour_subtotal?: string|null,
     *   subtotal?: string|null,
     * }  $input
     * @return array{
     *   calculation_basis: string,
     *   consumption_qty: string,
     *   rate: string,
     *   base_rate: string|null,
     *   factor: string|null,
     *   percent_value: string|null,
     *   wastage_percent: string,
     *   calculated_rate: string|null,
     *   amount_per_boq_unit: string,
     *   derivation_trace: string|null
     * }
     */
    public static function component(array $input): array
    {
        $basis = CostCalculationBasis::tryFrom((string) ($input['calculation_basis'] ?? CostCalculationBasis::QuantityRate->value))
            ?? throw ValidationException::withMessages(['calculation_basis' => 'Unsupported calculation basis.']);

        $wastage = Decimal::of($input['wastage_percent'] ?? '0')->round(Decimal::PERCENT_SCALE);
        if ($wastage->isNegative() || $wastage->greaterThan(self::MAX_WASTAGE_PERCENT)) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage must be between 0 and 100%.']);
        }
        $wasteFactor = Decimal::of('1')->plus($wastage->dividedBy(100, 10));

        return match ($basis) {
            CostCalculationBasis::QuantityRate => self::quantityRate($input, $wastage, $wasteFactor),
            CostCalculationBasis::BaseRateMultiplyFactor => self::derivedRate($input, $basis, $wastage, $wasteFactor),
            CostCalculationBasis::BaseRateDivideFactor => self::derivedRate($input, $basis, $wastage, $wasteFactor),
            CostCalculationBasis::FixedPerBoqUnit => self::fixedPerUnit($input, $wastage),
            CostCalculationBasis::PercentOfMaterialSubtotal,
            CostCalculationBasis::PercentOfLabourSubtotal,
            CostCalculationBasis::PercentOfSubtotal => self::percentOf($input, $basis, $wastage),
        };
    }

    /**
     * Deterministic multi-pass calculation for a sheet of components.
     *
     * Order: quantity/rate & derived rates & fixed → type subtotals → percent rows → total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function calculateSheet(array $rows): array
    {
        $pass1 = [];
        foreach (array_values($rows) as $i => $row) {
            $basis = CostCalculationBasis::tryFrom((string) ($row['calculation_basis'] ?? CostCalculationBasis::QuantityRate->value))
                ?? CostCalculationBasis::QuantityRate;

            if ($basis->isPercentBased()) {
                $pass1[$i] = $row + ['_pending_percent' => true];

                continue;
            }

            $calc = self::component($row);
            $pass1[$i] = array_merge($row, $calc, ['_pending_percent' => false]);
        }

        $material = Decimal::zero();
        $labour = Decimal::zero();
        $equipment = Decimal::zero();
        $subcontract = Decimal::zero();
        $other = Decimal::zero();

        foreach ($pass1 as $row) {
            if ($row['_pending_percent'] ?? false) {
                continue;
            }
            $type = (string) ($row['component_type'] ?? CostComponentType::Other->value);
            $amt = Decimal::of($row['amount_per_boq_unit'] ?? '0');
            match ($type) {
                CostComponentType::Material->value => $material = $material->plus($amt),
                CostComponentType::Labour->value => $labour = $labour->plus($amt),
                CostComponentType::Equipment->value => $equipment = $equipment->plus($amt),
                CostComponentType::Subcontract->value => $subcontract = $subcontract->plus($amt),
                default => $other = $other->plus($amt),
            };
        }

        $subtotal = $material->plus($labour)->plus($equipment)->plus($subcontract)->plus($other);

        $out = [];
        foreach ($pass1 as $i => $row) {
            if (! ($row['_pending_percent'] ?? false)) {
                unset($row['_pending_percent']);
                $out[$i] = $row;

                continue;
            }

            $calc = self::component(array_merge($row, [
                'material_subtotal' => $material->toRate(),
                'labour_subtotal' => $labour->toRate(),
                'subtotal' => $subtotal->toRate(),
            ]));
            unset($row['_pending_percent']);
            $out[$i] = array_merge($row, $calc);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Read-only category totals from authoritative component amounts (already include wastage / derived / %).
     * Inactive components are ignored. Does not re-apply wastage.
     *
     * @param  iterable<array<string, mixed>|object>  $components
     * @return array{
     *   material: string,
     *   labour: string,
     *   equipment: string,
     *   subcontract: string,
     *   other: string,
     *   total: string
     * }
     */
    public static function categoryTotals(iterable $components): array
    {
        $sums = [
            CostComponentType::Material->value => Decimal::zero(),
            CostComponentType::Labour->value => Decimal::zero(),
            CostComponentType::Equipment->value => Decimal::zero(),
            CostComponentType::Subcontract->value => Decimal::zero(),
            CostComponentType::Other->value => Decimal::zero(),
        ];

        foreach ($components as $c) {
            $active = is_array($c)
                ? ($c['is_active'] ?? true)
                : ($c->is_active ?? true);
            if (! $active) {
                continue;
            }

            $type = is_array($c)
                ? (string) ($c['component_type'] ?? CostComponentType::Other->value)
                : (string) ($c->component_type instanceof CostComponentType
                    ? $c->component_type->value
                    : $c->component_type);
            if (! array_key_exists($type, $sums)) {
                $type = CostComponentType::Other->value;
            }

            $amount = is_array($c)
                ? (string) ($c['amount_per_boq_unit'] ?? '0')
                : (string) ($c->amount_per_boq_unit ?? '0');
            $sums[$type] = $sums[$type]->plus($amount);
        }

        $material = $sums[CostComponentType::Material->value]->round(Decimal::RATE_SCALE)->toRate();
        $labour = $sums[CostComponentType::Labour->value]->round(Decimal::RATE_SCALE)->toRate();
        $equipment = $sums[CostComponentType::Equipment->value]->round(Decimal::RATE_SCALE)->toRate();
        $subcontract = $sums[CostComponentType::Subcontract->value]->round(Decimal::RATE_SCALE)->toRate();
        $other = $sums[CostComponentType::Other->value]->round(Decimal::RATE_SCALE)->toRate();
        $total = Decimal::sum([$material, $labour, $equipment, $subcontract, $other])
            ->round(Decimal::RATE_SCALE)
            ->toRate();

        return [
            'material' => $material,
            'labour' => $labour,
            'equipment' => $equipment,
            'subcontract' => $subcontract,
            'other' => $other,
            'total' => $total,
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function quantityRate(array $input, Decimal $wastage, Decimal $wasteFactor): array
    {
        $qty = Decimal::of($input['consumption_qty'] ?? '0')->round(Decimal::QTY_SCALE);
        $rate = Decimal::of($input['rate'] ?? '0')->round(Decimal::RATE_SCALE);
        if ($qty->isNegative() || $rate->isNegative()) {
            throw ValidationException::withMessages(['component' => 'Consumption and rate cannot be negative.']);
        }

        $amount = $qty->times($rate)->times($wasteFactor)->round(Decimal::RATE_SCALE);
        $trace = null;
        if (! $wastage->isZero()) {
            $trace = $qty->toQuantity().' × '.$rate->toRate().' × (1 + '.$wastage->toString().'/100) = '.$amount->toRate();
        } else {
            $trace = $qty->toQuantity().' × '.$rate->toRate().' = '.$amount->toRate();
        }

        return [
            'calculation_basis' => CostCalculationBasis::QuantityRate->value,
            'consumption_qty' => $qty->toQuantity(),
            'rate' => $rate->toRate(),
            'base_rate' => null,
            'factor' => null,
            'percent_value' => null,
            'wastage_percent' => $wastage->toString(),
            'calculated_rate' => $rate->toRate(),
            'amount_per_boq_unit' => $amount->toRate(),
            'derivation_trace' => $trace,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function derivedRate(array $input, CostCalculationBasis $basis, Decimal $wastage, Decimal $wasteFactor): array
    {
        $baseRate = Decimal::of($input['base_rate'] ?? '0')->round(Decimal::RATE_SCALE);
        $factor = Decimal::of($input['factor'] ?? '0')->round(Decimal::RATE_SCALE);
        $qty = Decimal::of($input['consumption_qty'] ?? '1')->round(Decimal::QTY_SCALE);

        if ($baseRate->isNegative() || $factor->isNegative() || $qty->isNegative()) {
            throw ValidationException::withMessages(['component' => 'Base rate, factor and consumption cannot be negative.']);
        }
        if (! $factor->isPositive()) {
            throw ValidationException::withMessages(['factor' => 'Factor must be positive.']);
        }

        $calcRate = $basis === CostCalculationBasis::BaseRateMultiplyFactor
            ? $baseRate->times($factor)->round(Decimal::RATE_SCALE)
            : $baseRate->dividedBy($factor, Decimal::RATE_SCALE)->round(Decimal::RATE_SCALE);

        $symbol = $basis === CostCalculationBasis::BaseRateMultiplyFactor ? '×' : '÷';
        $rateTrace = '₹'.$baseRate->toRate().' '.$symbol.' '.$factor->toRate().' = ₹'.$calcRate->toRate();

        $amount = $qty->times($calcRate)->times($wasteFactor)->round(Decimal::RATE_SCALE);
        $ref = trim((string) ($input['factor_reference'] ?? ''));
        if ($ref !== '') {
            $rateTrace .= ' ('.$ref.')';
        }

        return [
            'calculation_basis' => $basis->value,
            'consumption_qty' => $qty->toQuantity(),
            'rate' => $calcRate->toRate(),
            'base_rate' => $baseRate->toRate(),
            'factor' => $factor->toRate(),
            'percent_value' => null,
            'wastage_percent' => $wastage->toString(),
            'calculated_rate' => $calcRate->toRate(),
            'amount_per_boq_unit' => $amount->toRate(),
            'derivation_trace' => $rateTrace,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function fixedPerUnit(array $input, Decimal $wastage): array
    {
        $amount = Decimal::of($input['amount_per_boq_unit'] ?? $input['rate'] ?? '0')->round(Decimal::RATE_SCALE);
        if ($amount->isNegative()) {
            throw ValidationException::withMessages(['amount_per_boq_unit' => 'Fixed amount cannot be negative.']);
        }
        if (! $wastage->isZero()) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage is not applied to fixed-per-unit components.']);
        }

        return [
            'calculation_basis' => CostCalculationBasis::FixedPerBoqUnit->value,
            'consumption_qty' => '1.0000',
            'rate' => $amount->toRate(),
            'base_rate' => null,
            'factor' => null,
            'percent_value' => null,
            'wastage_percent' => '0.0000',
            'calculated_rate' => $amount->toRate(),
            'amount_per_boq_unit' => $amount->toRate(),
            'derivation_trace' => 'Fixed ₹'.$amount->toRate().' / BOQ unit',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function percentOf(array $input, CostCalculationBasis $basis, Decimal $wastage): array
    {
        if (! $wastage->isZero()) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage is not applied to percentage components.']);
        }

        $percent = Decimal::of($input['percent_value'] ?? '0')->round(Decimal::PERCENT_SCALE);
        if ($percent->isNegative()) {
            throw ValidationException::withMessages(['percent_value' => 'Percent cannot be negative.']);
        }

        $baseKey = match ($basis) {
            CostCalculationBasis::PercentOfMaterialSubtotal => 'material_subtotal',
            CostCalculationBasis::PercentOfLabourSubtotal => 'labour_subtotal',
            CostCalculationBasis::PercentOfSubtotal => 'subtotal',
            default => 'subtotal',
        };
        $base = Decimal::of($input[$baseKey] ?? '0')->round(Decimal::RATE_SCALE);
        if ($base->isNegative()) {
            throw ValidationException::withMessages([$baseKey => 'Subtotal cannot be negative.']);
        }

        $amount = $base->percentOf($percent)->round(Decimal::RATE_SCALE);
        $trace = '₹'.$base->toRate().' × '.$percent->toString().'% = ₹'.$amount->toRate();

        return [
            'calculation_basis' => $basis->value,
            'consumption_qty' => '0.0000',
            'rate' => $amount->toRate(),
            'base_rate' => $base->toRate(),
            'factor' => null,
            'percent_value' => $percent->toString(),
            'wastage_percent' => '0.0000',
            'calculated_rate' => $amount->toRate(),
            'amount_per_boq_unit' => $amount->toRate(),
            'derivation_trace' => $trace,
        ];
    }
}

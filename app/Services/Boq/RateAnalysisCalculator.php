<?php

namespace App\Services\Boq;

use App\Enums\Boq\ResourceType;
use App\Support\Math\Decimal;
use InvalidArgumentException;

/**
 * Rate analysis maths (architecture K.1):
 *
 *   effective_qty = quantity × (1 + wastage% / 100)
 *   amount        = round2(effective_qty × rate)
 *   head cost     = Σ amount per resource type
 *   direct        = Σ heads
 *   overhead      = round2(direct × overhead% / 100)
 *   profit        = round2((direct + overhead) × profit% / 100)
 *   total_cost    = direct + overhead + profit
 *   unit_rate     = round4(total_cost / output_quantity)
 */
class RateAnalysisCalculator
{
    /**
     * @param  array{output_quantity: string, overhead_percent?: string|null, profit_percent?: string|null}  $header
     * @param  list<array{resource_type: ResourceType|string, quantity?: string|null, wastage_percent?: string|null, rate?: string|null}>  $items
     * @return array{items: list<array{effective_quantity: string, amount: string}>, material_cost: string, labour_cost: string, equipment_cost: string, subcontract_cost: string, other_cost: string, direct_cost: string, overhead_amount: string, profit_amount: string, total_cost: string, unit_rate: string}
     */
    public static function calculate(array $header, array $items): array
    {
        $outputQty = Decimal::of($header['output_quantity'])->round(Decimal::QTY_SCALE);
        if (! $outputQty->isPositive()) {
            throw new InvalidArgumentException('Output quantity must be greater than zero.');
        }

        $heads = [];
        foreach (ResourceType::cases() as $type) {
            $heads[$type->value] = Decimal::zero();
        }

        $lines = [];
        foreach ($items as $item) {
            $type = $item['resource_type'] instanceof ResourceType ? $item['resource_type'] : ResourceType::from($item['resource_type']);
            $line = self::line($item['quantity'] ?? '0', $item['wastage_percent'] ?? '0', $item['rate'] ?? '0');
            $heads[$type->value] = $heads[$type->value]->plus($line['amount']);
            $lines[] = $line;
        }

        $direct = Decimal::sum($heads);
        $overhead = $direct->percentOf(Decimal::of($header['overhead_percent'] ?? '0'))->round();
        $profit = $direct->plus($overhead)->percentOf(Decimal::of($header['profit_percent'] ?? '0'))->round();
        $total = $direct->plus($overhead)->plus($profit);

        return [
            'items' => $lines,
            'material_cost' => $heads['material']->toMoney(),
            'labour_cost' => $heads['labour']->toMoney(),
            'equipment_cost' => $heads['equipment']->toMoney(),
            'subcontract_cost' => $heads['subcontract']->toMoney(),
            'other_cost' => $heads['other']->toMoney(),
            'direct_cost' => $direct->toMoney(),
            'overhead_amount' => $overhead->toMoney(),
            'profit_amount' => $profit->toMoney(),
            'total_cost' => $total->toMoney(),
            'unit_rate' => $total->dividedBy($outputQty, Decimal::RATE_SCALE)->toString(),
        ];
    }

    /**
     * @return array{effective_quantity: string, amount: string}
     */
    public static function line(string $quantity, string $wastagePercent, string $rate): array
    {
        $qty = Decimal::of($quantity)->round(Decimal::QTY_SCALE);
        $effective = $qty->plus($qty->percentOf(Decimal::of($wastagePercent)->round(Decimal::PERCENT_SCALE)));

        return [
            'effective_quantity' => $effective->toQuantity(),
            'amount' => $effective->times(Decimal::of($rate)->round(Decimal::RATE_SCALE))->toMoney(),
        ];
    }

    /**
     * Per-unit direct cost rates used when an analysis is applied to a BOQ line.
     * "Other" resources are carried in the material rate (the BOQ has four cost columns).
     *
     * @param  array{output_quantity: string, material_cost: string, labour_cost: string, equipment_cost: string, subcontract_cost: string, other_cost: string}  $analysis
     * @return array{material_rate: string, labour_rate: string, equipment_rate: string, subcontract_rate: string}
     */
    public static function perUnitRates(array $analysis): array
    {
        $oq = Decimal::of($analysis['output_quantity']);
        if (! $oq->isPositive()) {
            throw new InvalidArgumentException('Output quantity must be greater than zero.');
        }

        return [
            'material_rate' => Decimal::of($analysis['material_cost'])->plus($analysis['other_cost'])->dividedBy($oq, Decimal::RATE_SCALE)->toString(),
            'labour_rate' => Decimal::of($analysis['labour_cost'])->dividedBy($oq, Decimal::RATE_SCALE)->toString(),
            'equipment_rate' => Decimal::of($analysis['equipment_cost'])->dividedBy($oq, Decimal::RATE_SCALE)->toString(),
            'subcontract_rate' => Decimal::of($analysis['subcontract_cost'])->dividedBy($oq, Decimal::RATE_SCALE)->toString(),
        ];
    }
}

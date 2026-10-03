<?php

namespace App\Services\Boq;

use App\Support\Math\Decimal;

/**
 * Server-authoritative BOQ line maths (architecture K.1). All values are decimal strings.
 *
 *   cost_rate     = material + labour + equipment + subcontract          (4 dp)
 *   cost_amount   = round2(quantity × cost_rate)
 *   selling_rate  = round4(cost_rate × (1 + margin% / 100))
 *   client_rate   = explicit override, otherwise selling_rate             (4 dp)
 *   client_amount = round2(quantity × client_rate)
 *
 * BOQ totals are the sum of the rounded line amounts, so the header always equals the lines.
 */
class BoqCalculator
{
    public const RATE_FIELDS = ['material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate'];

    /**
     * @param  array{quantity?: string|null, material_rate?: string|null, labour_rate?: string|null, equipment_rate?: string|null, subcontract_rate?: string|null, margin_percent?: string|null, client_rate?: string|null}  $input
     * @return array{quantity: string, material_rate: string, labour_rate: string, equipment_rate: string, subcontract_rate: string, cost_rate: string, cost_amount: string, margin_percent: string, selling_rate: string, client_rate: string, client_amount: string}
     */
    public static function line(array $input): array
    {
        $quantity = Decimal::of($input['quantity'] ?? '0')->round(Decimal::QTY_SCALE);

        $rates = [];
        foreach (self::RATE_FIELDS as $field) {
            $rates[$field] = Decimal::of($input[$field] ?? '0')->round(Decimal::RATE_SCALE);
        }

        $costRate = Decimal::sum($rates)->round(Decimal::RATE_SCALE);
        $margin = Decimal::of($input['margin_percent'] ?? '0')->round(Decimal::PERCENT_SCALE);
        $sellingRate = $costRate->plus($costRate->percentOf($margin))->round(Decimal::RATE_SCALE);

        $override = $input['client_rate'] ?? null;
        $clientRate = ($override === null || $override === '')
            ? $sellingRate
            : Decimal::of($override)->round(Decimal::RATE_SCALE);

        return [
            'quantity' => $quantity->toString(),
            'material_rate' => $rates['material_rate']->toString(),
            'labour_rate' => $rates['labour_rate']->toString(),
            'equipment_rate' => $rates['equipment_rate']->toString(),
            'subcontract_rate' => $rates['subcontract_rate']->toString(),
            'cost_rate' => $costRate->toString(),
            'cost_amount' => $quantity->times($costRate)->toMoney(),
            'margin_percent' => $margin->toString(),
            'selling_rate' => $sellingRate->toString(),
            'client_rate' => $clientRate->toString(),
            'client_amount' => $quantity->times($clientRate)->toMoney(),
        ];
    }

    /**
     * Margin implied by a client rate over a cost rate: (client − cost) / cost × 100, 4 dp.
     * Zero when there is no cost to compare against.
     */
    public static function impliedMargin(string $costRate, string $clientRate): string
    {
        $cost = Decimal::of($costRate);
        if (! $cost->isPositive()) {
            return Decimal::zero()->round(Decimal::PERCENT_SCALE)->toString();
        }

        return Decimal::of($clientRate)->minus($cost)->times(100)->dividedBy($cost, Decimal::PERCENT_SCALE)->toString();
    }
}

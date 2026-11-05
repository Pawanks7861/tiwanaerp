<?php

namespace App\Services\Boq;

use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative material planning maths (Stage 4).
 *
 * Base = BOQ qty × consumption per BOQ unit
 * Wastage qty = Base × wastage% / 100
 * Required = Base + Wastage qty
 *
 * Cost Build-Up wastage (Stage 3) is separate and must not be applied again on top of these bases.
 */
class MaterialNormCalculator
{
    public const MAX_WASTAGE_PERCENT = '100';

    /**
     * @param  array{boq_qty?: string|null, consumption_qty?: string|null, wastage_percent?: string|null}  $input
     * @return array{
     *   consumption_qty: string,
     *   wastage_percent: string,
     *   base_requirement: string,
     *   wastage_qty: string,
     *   required_qty: string
     * }
     */
    public static function line(array $input): array
    {
        $boqQty = Decimal::of($input['boq_qty'] ?? '0')->round(Decimal::QTY_SCALE);
        $consumption = Decimal::of($input['consumption_qty'] ?? '0')->round(Decimal::QTY_SCALE);
        $wastage = Decimal::of($input['wastage_percent'] ?? '0')->round(Decimal::PERCENT_SCALE);

        if ($boqQty->isNegative() || $consumption->isNegative() || $wastage->isNegative()) {
            throw ValidationException::withMessages(['norm' => 'BOQ qty, consumption and wastage cannot be negative.']);
        }
        if ($wastage->greaterThan(self::MAX_WASTAGE_PERCENT)) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage cannot exceed 100%.']);
        }

        $base = $boqQty->times($consumption)->round(Decimal::QTY_SCALE);
        $wastageQty = $base->times($wastage)->dividedBy(100, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
        $required = $base->plus($wastageQty)->round(Decimal::QTY_SCALE);

        return [
            'consumption_qty' => $consumption->toQuantity(),
            'wastage_percent' => $wastage->round(Decimal::PERCENT_SCALE)->toString(),
            'base_requirement' => $base->toQuantity(),
            'wastage_qty' => $wastageQty->toQuantity(),
            'required_qty' => $required->toQuantity(),
        ];
    }

    public static function remaining(string $required, string $received): string
    {
        $gap = Decimal::of($required)->minus($received);

        return $gap->isNegative() ? Decimal::zero()->toQuantity() : $gap->round(Decimal::QTY_SCALE)->toQuantity();
    }

    public static function overProcured(string $required, string $received): string
    {
        $gap = Decimal::of($received)->minus($required);

        return $gap->isNegative() ? Decimal::zero()->toQuantity() : $gap->round(Decimal::QTY_SCALE)->toQuantity();
    }
}

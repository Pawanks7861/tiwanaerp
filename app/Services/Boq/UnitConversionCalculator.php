<?php

namespace App\Services\Boq;

use App\Enums\Boq\UnitConversionOperation;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Lightweight controlled UOM conversion (multiply / divide only). No dimensional analysis.
 */
class UnitConversionCalculator
{
    /**
     * @param  array{
     *   quantity: string,
     *   operation: string|UnitConversionOperation,
     *   factor: string
     * }  $input
     * @return array{result: string, derivation_trace: string}
     */
    public static function convert(array $input): array
    {
        $qty = Decimal::of($input['quantity'] ?? '0')->round(Decimal::QTY_SCALE);
        if ($qty->isNegative()) {
            throw ValidationException::withMessages(['quantity' => 'Quantity cannot be negative.']);
        }

        $op = $input['operation'] instanceof UnitConversionOperation
            ? $input['operation']
            : (UnitConversionOperation::tryFrom((string) ($input['operation'] ?? ''))
                ?? throw ValidationException::withMessages(['operation' => 'Operation must be multiply or divide.']));

        $factor = Decimal::of($input['factor'] ?? '0')->round(Decimal::RATE_SCALE);
        if (! $factor->isPositive()) {
            throw ValidationException::withMessages(['factor' => 'Conversion factor must be positive.']);
        }

        $result = match ($op) {
            UnitConversionOperation::Multiply => $qty->times($factor)->round(Decimal::QTY_SCALE),
            UnitConversionOperation::Divide => $qty->dividedBy($factor, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE),
        };

        $symbol = $op === UnitConversionOperation::Multiply ? '×' : '÷';
        $trace = $qty->toQuantity().' '.$symbol.' '.$factor->toRate().' = '.$result->toQuantity();

        return [
            'result' => $result->toQuantity(),
            'derivation_trace' => $trace,
        ];
    }
}

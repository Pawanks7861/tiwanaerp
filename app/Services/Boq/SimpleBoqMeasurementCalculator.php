<?php

namespace App\Services\Boq;

use App\Enums\Boq\SimpleBoqMeasurementFormula;
use App\Enums\Boq\SimpleBoqMeasurementMode;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative Normal / Simplified BOQ quantity from Nos / L / W / H.
 * Reuses MeasurementCalculator whitelisted formulas. Never parses item description.
 * Historical rows (null mode) keep the stored manual quantity.
 */
class SimpleBoqMeasurementCalculator
{
    /**
     * @param  array{
     *   measurement_mode?: string|null,
     *   measurement_formula?: string|null,
     *   measurement_nos?: string|null,
     *   measurement_length?: string|null,
     *   measurement_width?: string|null,
     *   measurement_height?: string|null,
     *   quantity?: string|null,
     *   unit_symbol?: string|null,
     *   allow_zero?: bool,
     * }  $input
     * @return array{
     *   measurement_mode: string|null,
     *   measurement_formula: string|null,
     *   measurement_nos: string|null,
     *   measurement_length: string|null,
     *   measurement_width: string|null,
     *   measurement_height: string|null,
     *   quantity: string,
     *   calculated: bool
     * }
     */
    public static function resolve(array $input): array
    {
        $modeRaw = $input['measurement_mode'] ?? null;
        $mode = $modeRaw !== null && $modeRaw !== ''
            ? (SimpleBoqMeasurementMode::tryFrom((string) $modeRaw)
                ?? throw ValidationException::withMessages(['measurement_mode' => 'Choose Manual or Auto measurement.']))
            : null;

        $nos = self::nullableQty($input['measurement_nos'] ?? null, 'measurement_nos');
        $length = self::nullableQty($input['measurement_length'] ?? null, 'measurement_length');
        $width = self::nullableQty($input['measurement_width'] ?? null, 'measurement_width');
        $height = self::nullableQty($input['measurement_height'] ?? null, 'measurement_height');
        $manualQty = self::nullableQty($input['quantity'] ?? null, 'quantity') ?? Decimal::zero();
        $allowZero = (bool) ($input['allow_zero'] ?? true);

        // Historical / unset mode: never recalculate to zero from blank dimensions.
        if ($mode === null || $mode === SimpleBoqMeasurementMode::Manual) {
            if (! $allowZero && ! $manualQty->isPositive()) {
                throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
            }

            return [
                'measurement_mode' => $mode?->value,
                'measurement_formula' => self::formulaOrNull($input['measurement_formula'] ?? null)?->value,
                'measurement_nos' => $nos?->toQuantity(),
                'measurement_length' => $length?->toQuantity(),
                'measurement_width' => $width?->toQuantity(),
                'measurement_height' => $height?->toQuantity(),
                'quantity' => $manualQty->toQuantity(),
                'calculated' => false,
            ];
        }

        $formula = self::resolveFormula($input['measurement_formula'] ?? null, $input['unit_symbol'] ?? null);

        $calc = MeasurementCalculator::line([
            'formula_type' => $formula->measurementFormulaType()->value,
            'nos' => $nos?->toQuantity(),
            'length' => $length?->toQuantity(),
            'width' => $width?->toQuantity(),
            'height_depth' => $height?->toQuantity(),
        ]);

        $qty = Decimal::of($calc['calculated_quantity']);
        if (! $allowZero && ! $qty->isPositive()) {
            throw ValidationException::withMessages(['quantity' => 'Calculated quantity must be greater than zero.']);
        }

        return [
            'measurement_mode' => $mode->value,
            'measurement_formula' => $formula->value,
            'measurement_nos' => $nos?->toQuantity(),
            'measurement_length' => $length?->toQuantity(),
            'measurement_width' => $width?->toQuantity(),
            'measurement_height' => $height?->toQuantity(),
            'quantity' => $qty->toQuantity(),
            'calculated' => true,
        ];
    }

    /**
     * Preview helper for Vue — same rules, no throw on incomplete draft dims when allow_zero.
     *
     * @param  array<string, mixed>  $input
     */
    public static function previewQuantity(array $input): ?string
    {
        try {
            return self::resolve([...$input, 'allow_zero' => true])['quantity'];
        } catch (ValidationException) {
            return null;
        }
    }

    private static function resolveFormula(mixed $raw, mixed $unitSymbol): SimpleBoqMeasurementFormula
    {
        if ($raw !== null && $raw !== '') {
            return SimpleBoqMeasurementFormula::tryFrom((string) $raw)
                ?? throw ValidationException::withMessages([
                    'measurement_formula' => 'Choose a supported measurement formula (Nos, RMT, Sqm, or Cum).',
                ]);
        }

        return SimpleBoqMeasurementFormula::tryFromUnitSymbol(
            is_string($unitSymbol) ? $unitSymbol : null
        ) ?? throw ValidationException::withMessages([
            'measurement_formula' => 'Select a measurement formula, or switch to Manual quantity for this unit.',
        ]);
    }

    private static function formulaOrNull(mixed $raw): ?SimpleBoqMeasurementFormula
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return SimpleBoqMeasurementFormula::tryFrom((string) $raw);
    }

    private static function nullableQty(mixed $value, string $field): ?Decimal
    {
        if ($value === null || $value === '') {
            return null;
        }

        $qty = Decimal::of(is_string($value) || is_int($value) ? $value : (string) $value);
        if ($qty->isNegative()) {
            throw ValidationException::withMessages([$field => 'Measurement values cannot be negative.']);
        }

        return $qty->round(Decimal::QTY_SCALE);
    }
}

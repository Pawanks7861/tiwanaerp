<?php

namespace App\Services\Boq;

use App\Enums\Boq\SimpleBoqMeasurementFormula;
use App\Enums\Boq\SimpleBoqMeasurementMode;
use App\Models\Boq\BoqItem;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative Normal / Simplified BOQ quantity from NOB × Nos / L / W / H.
 * Reuses MeasurementCalculator whitelisted formulas. Never parses item description.
 * Historical rows (null mode) keep the stored manual quantity.
 * Missing / blank NOB is treated as 1 so legacy quantities are unchanged.
 */
class SimpleBoqMeasurementCalculator
{
    /**
     * @param  array{
     *   measurement_mode?: string|null,
     *   measurement_formula?: string|null,
     *   measurement_nob?: string|int|null,
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
     *   measurement_nob: string,
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

        $nob = self::positiveIntegerNob($input['measurement_nob'] ?? null);
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
                'measurement_nob' => $nob->toString(),
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

        $qty = Decimal::of($calc['calculated_quantity'])->times($nob)->round(Decimal::QTY_SCALE);
        if (! $allowZero && ! $qty->isPositive()) {
            throw ValidationException::withMessages(['quantity' => 'Calculated quantity must be greater than zero.']);
        }

        return [
            'measurement_mode' => $mode->value,
            'measurement_formula' => $formula->value,
            'measurement_nob' => $nob->toString(),
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

    /**
     * Effective Planning Nos = NOB × Nos (per building).
     * Missing / blank NOB → 1. Null/blank/non-positive Nos → null (Nos mapping unavailable).
     */
    public static function effectiveNos(mixed $nob, mixed $nos): ?Decimal
    {
        if ($nos === null || $nos === '') {
            return null;
        }

        $nosQty = Decimal::of(is_string($nos) || is_int($nos) || is_float($nos) ? (string) $nos : (string) $nos)
            ->round(Decimal::QTY_SCALE);
        if (! $nosQty->isPositive()) {
            return null;
        }

        $nobQty = ($nob === null || $nob === '')
            ? Decimal::of('1')
            : Decimal::of(is_string($nob) || is_int($nob) || is_float($nob) ? (string) $nob : (string) $nob);

        if (! $nobQty->isPositive()) {
            $nobQty = Decimal::of('1');
        }

        return $nosQty->times($nobQty)->round(Decimal::QTY_SCALE);
    }

    /**
     * Effective Nos from a BOQ item's measurement_nob × measurement_nos.
     * Advanced items without Nos still return null — do not invent counts.
     */
    public static function effectiveNosFromItem(BoqItem $item): ?Decimal
    {
        return self::effectiveNos($item->measurement_nob ?? null, $item->measurement_nos ?? null);
    }

    /**
     * Conversion factor BOQ Qty ÷ Effective Nos (NOB × Nos). Null when unavailable.
     */
    public static function conversionFactorFromItem(BoqItem $item): ?Decimal
    {
        $effective = self::effectiveNosFromItem($item);
        $qty = Decimal::of($item->quantity ?? '0')->round(Decimal::QTY_SCALE);
        if ($effective === null || ! $effective->isPositive() || ! $qty->isPositive()) {
            return null;
        }

        return $qty->dividedBy($effective, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
    }

    /**
     * Display helpers for Planning / API (null-safe).
     *
     * @return array{
     *   measurement_nob: int,
     *   nos_per_building: ?string,
     *   effective_nos: ?string
     * }
     */
    public static function nosBreakdown(BoqItem $item): array
    {
        $nob = (int) ($item->measurement_nob ?? 1);
        if ($nob < 1) {
            $nob = 1;
        }
        $perBuilding = ($item->measurement_nos !== null && $item->measurement_nos !== '')
            ? Decimal::of($item->measurement_nos)->toQuantity()
            : null;
        $effective = self::effectiveNosFromItem($item);

        return [
            'measurement_nob' => $nob,
            'nos_per_building' => $perBuilding,
            'effective_nos' => $effective?->toQuantity(),
        ];
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

    /**
     * Blank / missing NOB → 1 (historical compatibility). Rejects 0, negatives, non-integers, text.
     */
    private static function positiveIntegerNob(mixed $value): Decimal
    {
        if ($value === null || $value === '') {
            return Decimal::of('1');
        }

        $raw = is_string($value) || is_int($value) ? (string) $value : (string) $value;
        $raw = trim($raw);
        if (! preg_match('/^[1-9]\d*$/', $raw)) {
            throw ValidationException::withMessages([
                'measurement_nob' => 'NOB must be a whole number of 1 or more.',
            ]);
        }

        return Decimal::of($raw);
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

<?php

namespace App\Services\Boq;

use App\Enums\Boq\CircleConstantMode;
use App\Enums\Boq\MeasurementFormulaType;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Whitelisted measurement formulas. Never uses eval() or client-supplied expressions.
 *
 * Final line qty = base + addition − deduction (qty scale, HALF_UP).
 *
 * Circle coefficients (Brick Math decimals — never native float):
 * - mathematical_pi → high-precision π
 * - legacy_3_14 → exactly 3.14 (workbook reproduction)
 * - fraction_22_7 → exact 22÷7
 * - custom → positive user constant
 */
class MeasurementCalculator
{
    /** Formula/calculator profile version snapshotted with circle calculations. */
    public const FORMULA_VERSION = 'measurement-circle-v2';

    /**
     * High-precision mathematical π (decimal string for Brick Math).
     * Not 22/7 and not 3.14.
     */
    public const MATHEMATICAL_PI = '3.1415926535897932384626433832795';

    /** @deprecated Use MATHEMATICAL_PI. Kept only as an alias during transition. */
    public const PI = self::MATHEMATICAL_PI;

    /** Legacy workbook constant — exactly 3.14. */
    public const LEGACY_PI = '3.14';

    /**
     * @param  array{
     *   formula_type: string,
     *   nos?: string|null,
     *   length?: string|null,
     *   width?: string|null,
     *   height_depth?: string|null,
     *   diameter?: string|null,
     *   radius?: string|null,
     *   manual_quantity?: string|null,
     *   addition_quantity?: string|null,
     *   deduction_quantity?: string|null,
     *   circle_constant_mode?: string|null,
     *   circle_constant_value?: string|null,
     * }  $input
     * @return array{
     *   calculated_base: string,
     *   calculated_quantity: string,
     *   radius: string|null,
     *   circle_constant: string|null,
     *   circle_constant_mode: string|null,
     *   formula_version: string|null
     * }
     */
    public static function line(array $input): array
    {
        $type = MeasurementFormulaType::tryFrom((string) ($input['formula_type'] ?? ''))
            ?? throw ValidationException::withMessages(['formula_type' => 'Choose a supported measurement formula.']);

        $nos = self::nonNegative($input['nos'] ?? null, 'nos');
        $length = self::nonNegative($input['length'] ?? null, 'length');
        $width = self::nonNegative($input['width'] ?? null, 'width');
        $height = self::nonNegative($input['height_depth'] ?? null, 'height_depth');
        $diameter = self::nonNegative($input['diameter'] ?? null, 'diameter');
        $radius = self::nonNegative($input['radius'] ?? null, 'radius');
        $manual = self::nonNegative($input['manual_quantity'] ?? null, 'manual_quantity');
        $addition = self::nonNegative($input['addition_quantity'] ?? '0', 'addition_quantity') ?? Decimal::zero();
        $deduction = self::nonNegative($input['deduction_quantity'] ?? '0', 'deduction_quantity') ?? Decimal::zero();

        $resolvedRadius = $radius;
        $circleConstant = null;
        $circleMode = null;
        $formulaVersion = null;
        if (in_array($type, [MeasurementFormulaType::CircleArea, MeasurementFormulaType::CircularVolume], true)) {
            if ($resolvedRadius === null && $diameter !== null) {
                $resolvedRadius = $diameter->dividedBy(2, 10)->round(Decimal::QTY_SCALE);
            }
            if ($resolvedRadius === null) {
                throw ValidationException::withMessages(['radius' => 'Enter radius or diameter for circle formulas.']);
            }
            $resolved = self::resolveCircleConstant($input);
            $circleMode = $resolved['mode'];
            $circleConstant = $resolved['coefficient'];
            $formulaVersion = $resolved['formula_version'];
        }

        $pi = $circleConstant ?? self::MATHEMATICAL_PI;

        $base = match ($type) {
            MeasurementFormulaType::Number => self::require($nos, 'nos'),
            MeasurementFormulaType::Length => self::require($nos, 'nos')->times(self::require($length, 'length')),
            MeasurementFormulaType::Area => self::require($nos, 'nos')->times(self::require($length, 'length'))->times(self::require($width, 'width')),
            MeasurementFormulaType::Volume => self::require($nos, 'nos')
                ->times(self::require($length, 'length'))
                ->times(self::require($width, 'width'))
                ->times(self::require($height, 'height_depth')),
            MeasurementFormulaType::CircleArea => Decimal::of($pi)
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($nos, 'nos')),
            MeasurementFormulaType::CircularVolume => Decimal::of($pi)
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($height, 'height_depth'))
                ->times(self::require($nos, 'nos')),
            MeasurementFormulaType::Manual => self::require($manual, 'manual_quantity'),
        };

        $base = $base->round(Decimal::QTY_SCALE);
        $qty = $base->plus($addition)->minus($deduction)->round(Decimal::QTY_SCALE);

        if ($qty->isNegative()) {
            throw ValidationException::withMessages([
                'deduction_quantity' => 'Deduction cannot exceed base quantity plus additions.',
            ]);
        }

        return [
            'calculated_base' => $base->toQuantity(),
            'calculated_quantity' => $qty->toQuantity(),
            'radius' => $resolvedRadius?->toQuantity(),
            'circle_constant' => $circleConstant,
            'circle_constant_mode' => $circleMode,
            'formula_version' => $formulaVersion,
        ];
    }

    /**
     * @param  iterable<string|null>  $lineQuantities
     */
    public static function sheetTotal(iterable $lineQuantities): string
    {
        return Decimal::sum($lineQuantities)->round(Decimal::QTY_SCALE)->toQuantity();
    }

    /**
     * Exact 22÷7 as a Brick Math decimal string (not native float).
     */
    public static function fraction22Over7(): string
    {
        return Decimal::of('22')->dividedBy('7', 28)->toString();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{mode: string, coefficient: string, formula_version: string}
     */
    public static function resolveCircleConstant(array $input): array
    {
        $mode = CircleConstantMode::tryFrom((string) ($input['circle_constant_mode'] ?? CircleConstantMode::MathematicalPi->value))
            ?? CircleConstantMode::MathematicalPi;

        $coefficient = match ($mode) {
            CircleConstantMode::MathematicalPi => self::MATHEMATICAL_PI,
            CircleConstantMode::Legacy314 => self::LEGACY_PI,
            CircleConstantMode::Fraction227 => self::fraction22Over7(),
            CircleConstantMode::Custom => (function () use ($input) {
                $custom = Decimal::of($input['circle_constant_value'] ?? '0');
                if (! $custom->isPositive()) {
                    throw ValidationException::withMessages([
                        'circle_constant_value' => 'Custom circle constant must be positive.',
                    ]);
                }

                // Keep the configured constant as entered (trimmed decimal), not rate-rounded away.
                return $custom->toString();
            })(),
        };

        return [
            'mode' => $mode->value,
            'coefficient' => $coefficient,
            'formula_version' => self::FORMULA_VERSION,
        ];
    }

    private static function nonNegative(mixed $value, string $field): ?Decimal
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = Decimal::of((string) $value)->round(Decimal::QTY_SCALE);
        if ($d->isNegative()) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' cannot be negative.']);
        }

        return $d;
    }

    private static function require(?Decimal $value, string $field): Decimal
    {
        if ($value === null) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' is required.']);
        }

        return $value;
    }
}

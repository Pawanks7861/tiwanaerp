<?php

namespace App\Services\Boq;

use App\Enums\Boq\MeasurementFormulaType;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Whitelisted measurement formulas. Never uses eval() or client-supplied expressions.
 *
 * Final line qty = base + addition − deduction (qty scale, HALF_UP).
 * Circle formulas use π = 22/7 for deterministic construction practice (documented).
 */
class MeasurementCalculator
{
    /** Documented π for circle formulas (22/7). */
    public const PI = '3.1428571429';

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
     *   deduction_quantity?: string|null
     * }  $input
     * @return array{calculated_base: string, calculated_quantity: string, radius: string|null}
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

        // Derive radius from diameter when needed.
        $resolvedRadius = $radius;
        if (in_array($type, [MeasurementFormulaType::CircleArea, MeasurementFormulaType::CircularVolume], true)) {
            if ($resolvedRadius === null && $diameter !== null) {
                $resolvedRadius = $diameter->dividedBy(2, 10)->round(Decimal::QTY_SCALE);
            }
            if ($resolvedRadius === null) {
                throw ValidationException::withMessages(['radius' => 'Enter radius or diameter for circle formulas.']);
            }
        }

        $base = match ($type) {
            MeasurementFormulaType::Number => self::require($nos, 'nos'),
            MeasurementFormulaType::Length => self::require($nos, 'nos')->times(self::require($length, 'length')),
            MeasurementFormulaType::Area => self::require($nos, 'nos')->times(self::require($length, 'length'))->times(self::require($width, 'width')),
            MeasurementFormulaType::Volume => self::require($nos, 'nos')
                ->times(self::require($length, 'length'))
                ->times(self::require($width, 'width'))
                ->times(self::require($height, 'height_depth')),
            MeasurementFormulaType::CircleArea => Decimal::of(self::PI)
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($resolvedRadius, 'radius'))
                ->times(self::require($nos, 'nos')),
            MeasurementFormulaType::CircularVolume => Decimal::of(self::PI)
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
        ];
    }

    /**
     * @param  iterable<string|null>  $lineQuantities
     */
    public static function sheetTotal(iterable $lineQuantities): string
    {
        return Decimal::sum($lineQuantities)->toQuantity();
    }

    private static function nonNegative(mixed $value, string $field): ?Decimal
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decimal = Decimal::of((string) $value);
        if ($decimal->isNegative()) {
            throw ValidationException::withMessages([$field => 'Quantity dimensions cannot be negative.']);
        }

        return $decimal;
    }

    private static function require(?Decimal $value, string $field): Decimal
    {
        if ($value === null) {
            throw ValidationException::withMessages([$field => "Enter {$field} for this formula."]);
        }

        return $value;
    }
}

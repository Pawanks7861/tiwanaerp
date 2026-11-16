<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

/**
 * Whitelisted simple Normal-BOQ formulas. Maps onto MeasurementCalculator types.
 * Never derived from free-text description.
 */
enum SimpleBoqMeasurementFormula: string
{
    use HasOptions;

    case Number = 'number';
    case Length = 'length';
    case Area = 'area';
    case Volume = 'volume';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'Nos',
            self::Length => 'RMT (Nos × Length)',
            self::Area => 'Sqm (Nos × Length × Width)',
            self::Volume => 'Cum (Nos × Length × Width × Height)',
        };
    }

    public function measurementFormulaType(): MeasurementFormulaType
    {
        return match ($this) {
            self::Number => MeasurementFormulaType::Number,
            self::Length => MeasurementFormulaType::Length,
            self::Area => MeasurementFormulaType::Area,
            self::Volume => MeasurementFormulaType::Volume,
        };
    }

    /**
     * Safe default from a normalised unit symbol. Returns null when the unit is not dimensional.
     */
    public static function tryFromUnitSymbol(?string $symbol): ?self
    {
        $key = strtolower(trim((string) $symbol));
        $key = str_replace(['.', ' '], '', $key);

        return match ($key) {
            'cum', 'm3', 'cu.m', 'cum.', 'cmt', 'cubicmetre', 'cubicmeter' => self::Volume,
            'sqm', 'm2', 'sq.m', 'sqmt', 'sqmt.', 'squaremetre', 'squaremeter' => self::Area,
            'rmt', 'rm', 'r.mt', 'r.m', 'metre', 'meter', 'mtr', 'm' => self::Length,
            'nos', 'no', 'no.', 'nos.', 'each', 'ea', 'nr', 'number' => self::Number,
            default => null,
        };
    }
}

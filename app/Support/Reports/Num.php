<?php

namespace App\Support\Reports;

use App\Support\Math\Decimal;

/**
 * Exact conversion of SQL aggregates. MySQL returns DECIMAL sums as strings; SQLite (tests) may
 * return floats, which are formatted to 6 places before they enter Decimal (never cast to float).
 */
final class Num
{
    public static function dec(mixed $value): Decimal
    {
        return match (true) {
            $value instanceof Decimal => $value,
            $value === null || $value === '' => Decimal::zero(),
            is_float($value) => Decimal::of(sprintf('%.6F', $value)),
            is_int($value) => Decimal::of($value),
            default => Decimal::of((string) $value),
        };
    }

    public static function money(mixed $value): string
    {
        return self::dec($value)->toMoney();
    }

    public static function qty(mixed $value): string
    {
        return self::dec($value)->toQuantity();
    }

    public static function rate(mixed $value): string
    {
        return self::dec($value)->toRate();
    }

    /** part / whole × 100 to 2 places; null when the whole is zero. */
    public static function percent(mixed $part, mixed $whole): ?string
    {
        $whole = self::dec($whole);
        if ($whole->isZero()) {
            return null;
        }

        return self::dec($part)->dividedBy($whole)->times(100)->round(2)->toString();
    }
}

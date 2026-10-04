<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The few expressions that differ between MySQL (production) and SQLite (tests). Callers pass
 * column references they control; user input is always bound.
 */
final class Sql
{
    /**
     * Inclusive upper bound for a date column. Eloquent's date cast writes "Y-m-d 00:00:00" on
     * SQLite, so "<= Y-m-d" would drop that day; MySQL DATE columns compare the same either way.
     */
    public static function eod(string $date): string
    {
        return substr($date, 0, 10).' 23:59:59';
    }

    /** Whole days from $from to $to (both date expressions or bound placeholders). */
    public static function days(string $from, string $to): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(julianday({$to}) - julianday({$from}) AS INTEGER)"
            : "DATEDIFF({$to}, {$from})";
    }

    /** First day of the month of a date column, as YYYY-MM. */
    public static function month(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /** String concatenation of trusted column references / quoted literals. */
    public static function concat(string ...$parts): string
    {
        return DB::getDriverName() === 'sqlite' ? implode(' || ', $parts) : 'CONCAT('.implode(', ', $parts).')';
    }

    /** Case-insensitive LIKE operand for a column (MySQL collations are already case-insensitive). */
    public static function lower(string $column): string
    {
        return "LOWER({$column})";
    }
}

<?php

namespace App\Support\Exports;

/**
 * Stops spreadsheet applications from treating user text as a formula.
 */
class SpreadsheetText
{
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = ltrim($value, " \t\r\n");
        if ($trimmed === '' || ! in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return $value;
        }

        if (is_numeric($trimmed)) {
            return $value;
        }

        return "'".$value;
    }
}

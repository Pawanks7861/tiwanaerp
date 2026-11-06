<?php

namespace App\Support\Imports\Concerns;

trait NormalizesImportValues
{
    /** @var list<string> */
    private const EXCEL_ERRORS = [
        '#REF!', '#VALUE!', '#DIV/0!', '#N/A', '#NAME?', '#NUM!', '#NULL!', '#GETTING_DATA', '#FORMULA!',
    ];

    protected function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // Neutralise formula injection from spreadsheet cells.
        $trimmed = ltrim($text);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true) && ! is_numeric($trimmed)) {
            $text = ltrim($text, " \t=+@-");
        }

        return $text === '' ? null : $text;
    }

    /**
     * Preserve codes exactly (leading zeros, hyphens, slashes) without numeric coercion.
     */
    protected function codeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            // Spreadsheet may have coerced "001" to 1 — keep integer form as typed.
            return is_float($value) && floor($value) == $value
                ? (string) (int) $value
                : rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function boolish(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'yes', 'y', 'active', 'on'], true);
    }

    protected function number(mixed $value): ?string
    {
        if ($this->isExcelErrorValue($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        }

        $text = $this->str($value);
        if ($text === null) {
            return null;
        }

        $text = $this->stripCurrencyNoise($text);
        $text = str_replace(["\u{00A0}", ' '], '', $text);

        // Indian / western grouping: keep last decimal separator.
        if (substr_count($text, ',') > 0 && substr_count($text, '.') === 0) {
            // 1,250 or 1,250,50 (EU) — treat single trailing ,dd as decimal if 2 digits.
            if (preg_match('/^-?\d{1,3}(,\d{2})$/', $text)) {
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } else {
            $text = str_replace(',', '', $text);
        }

        if (! is_numeric($text)) {
            return null;
        }

        return $text;
    }

    /**
     * Parse GST cells: 18, "18%", percentage-formatted 0.18 → 18. Ambiguous bare 0.18 stays 0.18 with warning flag.
     *
     * @return array{0: ?string, 1: ?string} [value, warning]
     */
    protected function percentage(mixed $value, bool $cellLooksLikePercent = false): array
    {
        if ($this->isExcelErrorValue($value)) {
            return [null, null];
        }

        if (is_string($value) && str_contains($value, '%')) {
            $num = $this->number(str_replace('%', '', $value));

            return [$num, null];
        }

        if (is_float($value) || is_int($value)) {
            $raw = (float) $value;
            if ($cellLooksLikePercent || ($raw > 0 && $raw <= 1)) {
                if ($cellLooksLikePercent) {
                    return [rtrim(rtrim(sprintf('%.4F', $raw * 100), '0'), '.'), null];
                }
                if ($raw > 0 && $raw < 1) {
                    return [
                        rtrim(rtrim(sprintf('%.4F', $raw), '0'), '.'),
                        'GST value looks like a fraction; confirm it is not meant as a percentage.',
                    ];
                }
            }

            return [rtrim(rtrim(sprintf('%.4F', $raw), '0'), '.'), null];
        }

        $text = $this->str($value);
        if ($text === null) {
            return [null, null];
        }

        return [$this->number($text), null];
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $text = $this->str($value);
        if ($text === null) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isExampleRow(array $mapped): bool
    {
        foreach ($mapped as $value) {
            $text = strtoupper((string) ($this->str($value) ?? ''));
            if (str_starts_with($text, 'EXAMPLE') || $text === 'EXAMPLE ROW') {
                return true;
            }
        }

        return false;
    }

    protected function isExcelErrorValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtoupper(trim($value)), self::EXCEL_ERRORS, true);
    }

    protected function stripCurrencyNoise(string $text): string
    {
        $text = preg_replace('/^(rs\.?|inr|₹)\s*/iu', '', $text) ?? $text;
        $text = str_replace(['₹', 'Rs.', 'Rs', 'INR', 'inr'], '', $text);

        return trim($text);
    }
}

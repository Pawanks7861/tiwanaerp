<?php

namespace App\Support\Imports\Concerns;

trait NormalizesImportValues
{
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
        $text = $this->str($value);
        if ($text === null) {
            return null;
        }

        $text = str_replace([',', ' '], '', $text);
        if (! is_numeric($text)) {
            return null;
        }

        return $text;
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
}

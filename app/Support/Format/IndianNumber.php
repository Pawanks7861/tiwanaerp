<?php

namespace App\Support\Format;

use App\Support\Math\Decimal;

/**
 * Indian number formatting for printed documents (12,34,567.89) and amounts in words
 * (lakh / crore). Works on decimal strings only.
 */
final class IndianNumber
{
    public static function money(string|int|null $value): string
    {
        return self::group(Decimal::of($value === null ? '0' : (string) $value)->toMoney());
    }

    public static function quantity(string|int|null $value): string
    {
        $fixed = Decimal::of($value === null ? '0' : (string) $value)->toQuantity();
        $fixed = str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;

        return self::group($fixed);
    }

    public static function rupeesInWords(string $amount): string
    {
        $fixed = Decimal::of($amount)->abs()->toMoney();
        [$rupees, $paise] = explode('.', $fixed);

        $words = 'Rupees '.self::words((int) $rupees);
        if ((int) $paise > 0) {
            $words .= ' and '.self::words((int) $paise).' Paise';
        }

        return $words.' Only';
    }

    private static function group(string $fixed): string
    {
        $negative = str_starts_with($fixed, '-');
        $fixed = ltrim($fixed, '-');
        [$integer, $fraction] = array_pad(explode('.', $fixed, 2), 2, null);

        if (strlen($integer) > 3) {
            $last3 = substr($integer, -3);
            $rest = substr($integer, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $integer = $rest.','.$last3;
        }

        return ($negative ? '-' : '').$integer.($fraction !== null ? '.'.$fraction : '');
    }

    private static function words(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $size => $label) {
            if ($number >= $size) {
                $count = intdiv($number, $size);
                $parts[] = ($size === 10000000 ? self::words($count) : self::belowHundred($count)).' '.$label;
                $number %= $size;
            }
        }
        if ($number > 0) {
            $parts[] = self::belowHundred($number);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number < 20) {
            return $ones[$number];
        }

        return trim($tens[intdiv($number, 10)].' '.$ones[$number % 10]);
    }
}

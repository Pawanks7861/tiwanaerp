<?php

namespace App\Services\Ai\Local;

use Carbon\CarbonImmutable;

class DateRangeResolver
{
    /**
     * @return array{period: string, from?: string, to?: string}|null
     */
    public function extract(string $normalized, string $timezone = 'Asia/Kolkata'): ?array
    {
        if (str_contains($normalized, 'last_month') || str_contains($normalized, 'previous month')) {
            return ['period' => 'last_month'];
        }
        if (str_contains($normalized, 'today') || preg_match('/\btoday\b/', $normalized)) {
            return ['period' => 'today'];
        }
        if (str_contains($normalized, 'this_month') || str_contains($normalized, 'current month')) {
            return ['period' => 'this_month'];
        }

        $now = CarbonImmutable::now($timezone);
        $months = [
            'january' => 1, 'jan' => 1, 'जनवरी' => 1,
            'february' => 2, 'feb' => 2, 'फरवरी' => 2,
            'march' => 3, 'mar' => 3, 'मार्च' => 3,
            'april' => 4, 'apr' => 4, 'अप्रैल' => 4,
            'may' => 5, 'मई' => 5,
            'june' => 6, 'jun' => 6, 'जून' => 6,
            'july' => 7, 'jul' => 7, 'जुलाई' => 7,
            'august' => 8, 'aug' => 8, 'अगस्त' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9, 'सितंबर' => 9, 'सितम्बर' => 9,
            'october' => 10, 'oct' => 10, 'अक्टूबर' => 10,
            'november' => 11, 'nov' => 11, 'नवंबर' => 11, 'नवम्बर' => 11,
            'december' => 12, 'dec' => 12, 'दिसंबर' => 12, 'दिसम्बर' => 12,
        ];

        foreach ($months as $name => $num) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/u', $normalized)) {
                $year = $now->year;
                if (preg_match('/\b(20\d{2})\b/', $normalized, $m)) {
                    $year = (int) $m[1];
                } elseif ($num > $now->month + 1) {
                    $year = $now->year - 1;
                }
                $start = CarbonImmutable::create($year, $num, 1, 0, 0, 0, $timezone);

                return [
                    'period' => 'custom',
                    'from' => $start->toDateString(),
                    'to' => $start->endOfMonth()->toDateString(),
                ];
            }
        }

        return null;
    }
}

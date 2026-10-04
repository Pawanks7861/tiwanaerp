<?php

namespace App\Support\Reports;

use App\Models\Core\Company;
use App\Models\Core\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Financial years of a company (architecture: April–March for India). The company's
 * financial_years rows come first; the current and two previous years are derived from
 * companies.fy_start_month when no row exists, so reports never fall back to calendar years.
 */
final class ReportPeriod
{
    /**
     * @return list<array{value: string, label: string, start: string, end: string, current: bool}>
     */
    public static function options(Company $company): array
    {
        $today = self::today($company);
        $years = DB::table('financial_years')->where('company_id', $company->id)->orderByDesc('start_date')
            ->get(['name', 'start_date', 'end_date'])
            ->mapWithKeys(fn ($fy) => [(string) $fy->name => [
                'value' => (string) $fy->name,
                'label' => 'FY '.$fy->name,
                'start' => substr((string) $fy->start_date, 0, 10),
                'end' => substr((string) $fy->end_date, 0, 10),
            ]])->all();

        $current = self::derive($company, $today);
        for ($i = 0; $i < 3; $i++) {
            $fy = self::derive($company, CarbonImmutable::parse($current['start'])->subYears($i));
            $years[$fy['value']] ??= $fy;
        }

        $options = array_values($years);
        usort($options, fn ($a, $b) => strcmp($b['start'], $a['start']));

        return array_map(fn ($o) => $o + ['current' => $o['start'] <= $today && $today <= $o['end']], $options);
    }

    /**
     * @return array{value: string, label: string, start: string, end: string, current: bool}
     */
    public static function current(Company $company): array
    {
        $options = self::options($company);
        $marked = FinancialYear::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_current', true)->value('name');

        foreach ($options as $option) {
            if ($marked !== null && $option['value'] === $marked) {
                return $option;
            }
        }
        foreach ($options as $option) {
            if ($option['current']) {
                return $option;
            }
        }

        return $options[0];
    }

    /**
     * @return array{value: string, label: string, start: string, end: string, current: bool}|null
     */
    public static function find(Company $company, string $value): ?array
    {
        foreach (self::options($company) as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        return null;
    }

    /** Today's date in the company's time zone. */
    public static function today(Company $company): string
    {
        return CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
    }

    /**
     * @return array{value: string, label: string, start: string, end: string}
     */
    private static function derive(Company $company, CarbonImmutable|string $date): array
    {
        $date = $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);
        $month = max(1, min(12, (int) ($company->fy_start_month ?: 4)));
        $startYear = $date->month >= $month ? $date->year : $date->year - 1;
        $start = CarbonImmutable::create($startYear, $month, 1);
        $end = $start->addYear()->subDay();
        $name = $month === 1 ? (string) $startYear : $startYear.'-'.substr((string) ($startYear + 1), -2);

        return ['value' => $name, 'label' => 'FY '.$name, 'start' => $start->toDateString(), 'end' => $end->toDateString()];
    }
}

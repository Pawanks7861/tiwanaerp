<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\Utilities\ElectricityMeterReading;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;

final class ElectricityConsumptionTrendReport extends ReportDefinition
{
    public function key(): string
    {
        return 'electricity-consumption-trend';
    }

    public function title(): string
    {
        return 'Electricity Consumption Trend';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Monthly kWh / kVAh trend by utility site.';
    }

    public function permissions(): array
    {
        return ['utilities.electricity.view'];
    }

    public function scopes(): array
    {
        return ['global'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $rows = ElectricityMeterReading::query()
            ->with('site:id,name,code')
            ->where('status', '!=', ElectricityReadingStatus::Cancelled)
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->orderBy('billing_month')
            ->get()
            ->map(fn (ElectricityMeterReading $r) => [
                'site' => $r->site?->name,
                'month' => $r->billing_month?->toDateString(),
                'kwh' => (string) $r->kwh_consumption,
                'kvah' => $r->kvah_consumption !== null ? (string) $r->kvah_consumption : null,
                'billing_demand' => $r->billing_demand !== null ? (string) $r->billing_demand : null,
                'power_factor' => $r->power_factor !== null ? (string) $r->power_factor : null,
            ])->all();

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('month', 'Month', 'date'),
                self::col('kwh', 'kWh', 'number'),
                self::col('kvah', 'kVAh', 'number'),
                self::col('billing_demand', 'Billing demand', 'number', ['mobile' => false]),
                self::col('power_factor', 'PF', 'number', ['mobile' => false]),
            ],
            rows: $rows,
        );
    }
}

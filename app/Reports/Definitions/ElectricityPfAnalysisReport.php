<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\Utilities\ElectricityMeterReading;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;

final class ElectricityPfAnalysisReport extends ReportDefinition
{
    public function key(): string
    {
        return 'electricity-pf-analysis';
    }

    public function title(): string
    {
        return 'Electricity PF Analysis';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Power factor by site/month against connection-configured thresholds.';
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
            ->with(['site:id,name', 'connection:id,connection_number,low_pf_threshold'])
            ->where('status', '!=', ElectricityReadingStatus::Cancelled)
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->orderBy('billing_month')
            ->get()
            ->map(fn (ElectricityMeterReading $r) => [
                'site' => $r->site?->name,
                'connection' => $r->connection?->connection_number,
                'month' => $r->billing_month?->toDateString(),
                'kwh' => (string) $r->kwh_consumption,
                'kvah' => $r->kvah_consumption !== null ? (string) $r->kvah_consumption : null,
                'power_factor' => $r->power_factor !== null ? (string) $r->power_factor : null,
                'threshold' => $r->connection?->low_pf_threshold !== null ? (string) $r->connection->low_pf_threshold : null,
            ])->all();

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('connection', 'Connection', 'text', ['mobile' => false]),
                self::col('month', 'Month', 'date'),
                self::col('kwh', 'kWh', 'number', ['mobile' => false]),
                self::col('kvah', 'kVAh', 'number', ['mobile' => false]),
                self::col('power_factor', 'PF', 'number'),
                self::col('threshold', 'Threshold', 'number'),
            ],
            rows: $rows,
        );
    }
}

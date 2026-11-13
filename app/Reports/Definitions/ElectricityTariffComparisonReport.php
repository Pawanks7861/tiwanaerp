<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Utilities\ElectricityComparisonService;

final class ElectricityTariffComparisonReport extends ReportDefinition
{
    public function __construct(private readonly ElectricityComparisonService $comparison) {}

    public function key(): string
    {
        return 'electricity-tariff-comparison';
    }

    public function title(): string
    {
        return 'Electricity Tariff Comparison';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Side-by-side active tariff snapshot by site/connection for a selected date.';
    }

    public function permissions(): array
    {
        return ['utilities.electricity.view', 'utilities.electricity.manage_tariffs'];
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['global'];
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $includeCosts = $ctx->user->can('utilities.electricity.view_costs');
        $on = $ctx->to ?: now()->toDateString();
        $rows = $this->comparison->tariffsOn($on, $includeCosts);

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('connection', 'Connection', 'text'),
                self::col('tariff', 'Tariff', 'text'),
                self::col('contract_demand', 'Contract demand', 'number'),
                self::col('energy_rate', 'Energy rate', 'number'),
                self::col('demand_rate', 'Demand rate', 'number', ['mobile' => false]),
                self::col('fac_fppca', 'FAC/FPPCA', 'number', ['mobile' => false]),
                self::col('wheeling', 'Wheeling', 'number', ['mobile' => false]),
                self::col('duty', 'Duty', 'number', ['mobile' => false]),
            ],
            rows: $rows,
            notes: ['As of '.$on],
        );
    }
}

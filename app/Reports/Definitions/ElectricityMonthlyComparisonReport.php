<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Utilities\ElectricityComparisonService;

final class ElectricityMonthlyComparisonReport extends ReportDefinition
{
    public function __construct(private readonly ElectricityComparisonService $comparison) {}

    public function key(): string
    {
        return 'electricity-monthly-comparison';
    }

    public function title(): string
    {
        return 'Electricity Monthly Comparison';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Site-wise monthly kWh, kVAh, demand, PF, calculated vs actual bill and unit cost.';
    }

    public function permissions(): array
    {
        return ['utilities.electricity.view'];
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function scopes(): array
    {
        return ['global'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $includeCosts = $ctx->user->can('utilities.electricity.view_costs') || $ctx->user->can('reports.view_financial');
        $rows = $this->comparison->monthly($ctx->from, $ctx->to, null, $includeCosts);

        $columns = [
            self::col('site', 'Site', 'text'),
            self::col('state', 'State', 'text', ['mobile' => false]),
            self::col('month', 'Month', 'date'),
            self::col('kwh', 'kWh', 'number'),
            self::col('kvah', 'kVAh', 'number', ['mobile' => false]),
            self::col('billing_demand', 'Billing demand', 'number', ['mobile' => false]),
            self::col('contract_demand', 'Contract demand', 'number', ['mobile' => false]),
            self::col('demand_utilization_percent', 'Demand util %', 'number'),
            self::col('power_factor', 'PF', 'number'),
        ];
        if ($includeCosts) {
            $columns = array_merge($columns, [
                self::col('actual_bill', 'Actual bill', 'money'),
                self::col('calculated_bill', 'Calculated bill', 'money', ['mobile' => false]),
                self::col('variance', 'Variance', 'money'),
                self::col('cost_per_kwh', '₹/kWh', 'number'),
                self::col('cost_per_kvah', '₹/kVAh', 'number', ['mobile' => false]),
            ]);
        }

        return new ReportResult(columns: $columns, rows: $rows, notes: ['count: '.count($rows)]);
    }
}

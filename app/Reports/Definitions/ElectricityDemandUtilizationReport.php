<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;

final class ElectricityDemandUtilizationReport extends ReportDefinition
{
    public function key(): string
    {
        return 'electricity-demand-utilization';
    }

    public function title(): string
    {
        return 'Electricity Demand Utilization';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Billing demand vs contract/sanctioned demand with utilization % and excess.';
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
        $rows = ElectricityBill::query()
            ->with(['site:id,name', 'connection:id,connection_number,contract_demand,sanctioned_demand'])
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->orderBy('billing_month')
            ->get()
            ->map(fn (ElectricityBill $b) => [
                'site' => $b->site?->name,
                'connection' => $b->connection?->connection_number,
                'month' => $b->billing_month?->toDateString(),
                'billing_demand' => $b->reading_snapshot['billing_demand'] ?? null,
                'contract_demand' => $b->connection?->contract_demand !== null ? (string) $b->connection->contract_demand : null,
                'utilization' => $b->demand_utilization_percent !== null ? (string) $b->demand_utilization_percent : null,
                'excess' => $b->excess_demand !== null ? (string) $b->excess_demand : null,
            ])->all();

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('connection', 'Connection', 'text', ['mobile' => false]),
                self::col('month', 'Month', 'date'),
                self::col('billing_demand', 'Billing demand', 'number'),
                self::col('contract_demand', 'Contract demand', 'number'),
                self::col('utilization', 'Utilization %', 'number'),
                self::col('excess', 'Excess', 'number'),
            ],
            rows: $rows,
        );
    }
}

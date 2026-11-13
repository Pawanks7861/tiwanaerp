<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;

final class ElectricitySiteCostReport extends ReportDefinition
{
    public function key(): string
    {
        return 'electricity-site-cost';
    }

    public function title(): string
    {
        return 'Site-wise Electricity Cost';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Aggregated electricity cost, consumption and unit rates by utility site.';
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
        $bills = ElectricityBill::query()
            ->with('site:id,code,name')
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->get()
            ->groupBy('utility_site_id');

        $rows = [];
        foreach ($bills as $group) {
            $kwh = Decimal::sum(collect($group)->map(fn ($b) => $b->reading_snapshot['kwh_consumption'] ?? '0'));
            $cost = Decimal::sum(collect($group)->map(fn ($b) => $b->actual_bill_total ?? $b->calculated_total));
            $row = [
                'site' => $group->first()->site?->name,
                'site_code' => $group->first()->site?->code,
                'months' => $group->count(),
                'kwh' => $kwh->toQuantity(),
            ];
            if ($includeCosts) {
                $row['cost'] = $cost->toMoney();
                $row['cost_per_kwh'] = $kwh->isZero() ? null : $cost->dividedBy($kwh, 4)->toRate();
            }
            $rows[] = $row;
        }

        $columns = [
            self::col('site', 'Site', 'text'),
            self::col('site_code', 'Code', 'text', ['mobile' => false]),
            self::col('months', 'Months', 'number'),
            self::col('kwh', 'kWh', 'number'),
        ];
        if ($includeCosts) {
            $columns[] = self::col('cost', 'Cost', 'money');
            $columns[] = self::col('cost_per_kwh', '₹/kWh', 'number');
        }

        return new ReportResult(columns: $columns, rows: $rows);
    }
}

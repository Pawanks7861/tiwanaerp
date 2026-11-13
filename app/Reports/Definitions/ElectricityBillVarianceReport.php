<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;

final class ElectricityBillVarianceReport extends ReportDefinition
{
    public function key(): string
    {
        return 'electricity-bill-variance';
    }

    public function title(): string
    {
        return 'Electricity Bill Variance';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return 'Calculated vs actual bill totals and variance by site/month.';
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
        if (! $ctx->user->can('utilities.electricity.view_costs') && ! $ctx->user->can('reports.view_financial')) {
            return new ReportResult(columns: [], rows: [], notes: ['Costs redacted']);
        }

        $rows = ElectricityBill::query()
            ->with('site:id,name')
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->whereNotNull('actual_bill_total')
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->orderBy('billing_month')
            ->get()
            ->map(fn (ElectricityBill $b) => [
                'site' => $b->site?->name,
                'month' => $b->billing_month?->toDateString(),
                'bill_number' => $b->bill_number,
                'calculated' => (string) $b->calculated_total,
                'actual' => (string) $b->actual_bill_total,
                'variance' => $b->variance_total !== null ? (string) $b->variance_total : null,
                'status' => $b->status?->value,
            ])->all();

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('month', 'Month', 'date'),
                self::col('bill_number', 'Bill no.', 'text', ['mobile' => false]),
                self::col('calculated', 'Calculated', 'money'),
                self::col('actual', 'Actual', 'money'),
                self::col('variance', 'Variance', 'money'),
                self::col('status', 'Status', 'text', ['mobile' => false]),
            ],
            rows: $rows,
        );
    }
}

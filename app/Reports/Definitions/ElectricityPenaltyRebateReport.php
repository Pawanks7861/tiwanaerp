<?php

namespace App\Reports\Definitions;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityBillComponent;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;

class ElectricityPenaltyRebateReport extends ReportDefinition
{
    public function __construct(protected readonly bool $penalties = true) {}

    public function key(): string
    {
        return $this->penalties ? 'electricity-penalty' : 'electricity-rebate-subsidy';
    }

    public function title(): string
    {
        return $this->penalties ? 'Electricity Penalty Report' : 'Electricity Rebate / Subsidy Report';
    }

    public function category(): string
    {
        return 'utilities';
    }

    public function description(): string
    {
        return $this->penalties
            ? 'Non-zero penalty / PLEC / demand / PF penalty components.'
            : 'Rebate and subsidy components across bills.';
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
        if (! $ctx->user->can('utilities.electricity.view_costs')) {
            return new ReportResult(columns: [], rows: [], notes: ['Costs redacted']);
        }

        $billIds = ElectricityBill::query()
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($ctx->from, fn ($q) => $q->whereDate('billing_month', '>=', $ctx->from))
            ->when($ctx->to, fn ($q) => $q->whereDate('billing_month', '<=', $ctx->to))
            ->pluck('id');

        $q = ElectricityBillComponent::query()
            ->with(['bill.site:id,name'])
            ->whereIn('electricity_bill_id', $billIds)
            ->when($this->penalties, fn ($q) => $q->where('is_penalty', true), fn ($q) => $q->where('is_rebate', true));

        $rows = $q->get()->map(fn (ElectricityBillComponent $c) => [
            'site' => $c->bill?->site?->name,
            'month' => $c->bill?->billing_month?->toDateString(),
            'component' => $c->component_name,
            'calculated' => (string) $c->calculated_amount,
            'actual' => $c->actual_amount !== null ? (string) $c->actual_amount : null,
        ])->filter(fn ($r) => ((float) $r['calculated'] !== 0.0) || ((float) ($r['actual'] ?? 0) !== 0.0))->values()->all();

        return new ReportResult(
            columns: [
                self::col('site', 'Site', 'text'),
                self::col('month', 'Month', 'date'),
                self::col('component', 'Component', 'text'),
                self::col('calculated', 'Calculated', 'money'),
                self::col('actual', 'Actual', 'money'),
            ],
            rows: $rows,
        );
    }
}

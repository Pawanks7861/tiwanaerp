<?php

namespace App\Reports\Definitions;

use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Queries\Reports\CommittedCostQuery;
use App\Queries\Reports\ResourceCostQuery;
use App\Queries\Reports\Settlements;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subcontractor bills in the period with their deductions and payments (approved allocations up
 * to the period end), and a work-order section with value, cost certified so far and the open
 * (committed) balance.
 */
final class SubcontractReport extends ReportDefinition
{
    public function __construct(
        private readonly ResourceCostQuery $resources,
        private readonly CommittedCostQuery $committed,
    ) {}

    public function key(): string
    {
        return 'subcontract-bills';
    }

    public function title(): string
    {
        return 'Subcontractor Cost & Bills';
    }

    public function category(): string
    {
        return 'subcontract';
    }

    public function description(): string
    {
        return 'Certified subcontractor bills with deductions and payments, plus work-order value, certified cost and open commitment.';
    }

    public function permissions(): array
    {
        return ['subcontract.view'];
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return ['subcontractor', 'status', 'search'];
    }

    public function statusOptions(): array
    {
        return collect(SubcontractorBillStatus::cases())->filter(fn ($s) => $s->isCertified())
            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return $this->query($ctx)->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $base = $this->query($ctx);
        $paid = Settlements::allocated($cid, 'subcontractor_bill', $ctx->to);
        $withPaid = DB::query()->fromSub($base, 'b')->leftJoinSub($paid, 's', 's.payable_id', '=', 'b.id')
            ->select('b.*')->selectRaw('COALESCE(s.settled, 0) as settled');

        $sum = DB::query()->fromSub(clone $withPaid, 't')->selectRaw('COUNT(*) as cnt, SUM(gross_amount) as gross_amount, SUM(tax_amount) as tax_amount,
            SUM(retention_amount) as retention_amount, SUM(advance_recovery) as advance_recovery, SUM(tds_amount) as tds_amount,
            SUM(other_deductions) as other_deductions, SUM(net_payable) as net_payable, SUM(settled) as settled')->first();

        [$rows, $pagination] = $this->rows($withPaid->orderBy('b.bill_date')->orderBy('b.id'), $ctx, $paginate, fn ($r) => [
            'bill' => $r->bill_number,
            'url' => route('projects.subcontractor-bills.show', [$r->project_id, $r->id]),
            'project' => $r->project_code,
            'subcontractor' => $r->subcontractor,
            'work_order' => $r->wo_number,
            'date' => self::day($r->bill_date),
            'status' => self::enumLabel(SubcontractorBillStatus::class, $r->status),
            'gross_amount' => Num::money($r->gross_amount),
            'tax_amount' => Num::money($r->tax_amount),
            'retention_amount' => Num::money($r->retention_amount),
            'advance_recovery' => Num::money($r->advance_recovery),
            'tds_amount' => Num::money($r->tds_amount),
            'other_deductions' => Num::money($r->other_deductions),
            'net_payable' => Num::money($r->net_payable),
            'settled' => Num::money($r->settled),
        ]);

        $moneyKeys = ['gross_amount', 'tax_amount', 'retention_amount', 'advance_recovery', 'tds_amount', 'other_deductions', 'net_payable', 'settled'];
        $totals = ['bill' => 'Total ('.(int) ($sum->cnt ?? 0).')'];
        foreach ($moneyKeys as $key) {
            $totals[$key] = Num::money($sum->{$key} ?? null);
        }

        $orders = $this->workOrderSection($ctx);

        return new ReportResult(
            columns: [
                self::col('bill', 'Bill', 'code', ['link' => true]),
                self::col('project', 'Project', 'code'),
                self::col('subcontractor', 'Subcontractor'),
                self::col('work_order', 'Work order', 'code', ['mobile' => false]),
                self::col('date', 'Date', 'date'),
                self::col('status', 'Status', 'status', ['mobile' => false]),
                self::money('gross_amount', 'Gross'),
                self::money('tax_amount', 'GST', ['mobile' => false]),
                self::money('retention_amount', 'Retention', ['mobile' => false]),
                self::money('advance_recovery', 'Advance recovery', ['mobile' => false]),
                self::money('tds_amount', 'TDS', ['mobile' => false]),
                self::money('other_deductions', 'Other deductions', ['mobile' => false]),
                self::money('net_payable', 'Net payable'),
                self::money('settled', 'Paid'),
            ],
            rows: $rows,
            totals: $totals,
            cards: [
                ['key' => 'gross', 'label' => 'Certified (gross)', 'value' => $totals['gross_amount'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'net', 'label' => 'Net payable', 'value' => $totals['net_payable'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'paid', 'label' => 'Paid', 'value' => $totals['settled'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'committed', 'label' => 'Open on work orders', 'value' => $orders['open'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: ['Paid = allocations of approved payments dated on or before the period end. Work-order cost certified = subcontract cost posted to the ledger (net of reversals).'],
            pagination: $pagination,
            sections: [$orders['section']],
        );
    }

    /**
     * @return array{open: string, section: array<string, mixed>}
     */
    private function workOrderSection(ReportContext $ctx): array
    {
        $cid = $ctx->companyId();
        $open = collect($this->committed->workOrders($cid, $ctx->projectIds))->keyBy('id');
        $sub = $ctx->filter('subcontractor_id') ? (int) $ctx->filter('subcontractor_id') : null;
        $rows = [];
        $sum = ['value' => Decimal::zero(), 'posted' => Decimal::zero(), 'open' => Decimal::zero()];
        foreach ($this->resources->workOrders($cid, $ctx->projectIds, $sub)->orderBy('wo.wo_number')->get() as $wo) {
            $commit = $open->get((int) $wo->id);
            $value = Num::dec($wo->subtotal);
            $posted = $commit['posted'] ?? Decimal::zero();
            $remaining = $commit['open'] ?? Decimal::zero();
            $rows[] = [
                'wo' => $wo->wo_number,
                'url' => route('projects.work-orders.show', [$wo->project_id, $wo->id]),
                'project' => $wo->project_code,
                'subcontractor' => $wo->subcontractor,
                'status' => self::enumLabel(WorkOrderStatus::class, $wo->status),
                'value' => $value->toMoney(),
                'posted' => $commit ? $posted->toMoney() : null,
                'open' => $remaining->toMoney(),
            ];
            $sum['value'] = $sum['value']->plus($value);
            $sum['posted'] = $sum['posted']->plus($posted);
            $sum['open'] = $sum['open']->plus($remaining);
        }

        return [
            'open' => $sum['open']->toMoney(),
            'section' => [
                'title' => 'Work orders',
                'columns' => [
                    self::col('wo', 'Work order', 'code', ['link' => true]),
                    self::col('project', 'Project', 'code'),
                    self::col('subcontractor', 'Subcontractor'),
                    self::col('status', 'Status', 'status'),
                    self::money('value', 'Value (excl. GST)'),
                    self::money('posted', 'Cost certified'),
                    self::money('open', 'Open (committed)'),
                ],
                'rows' => $rows,
                'totals' => ['wo' => 'Total', 'value' => $sum['value']->toMoney(), 'posted' => $sum['posted']->toMoney(), 'open' => $sum['open']->toMoney()],
            ],
        ];
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->resources->subcontractBills($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, [
            'subcontractor_id' => $ctx->filter('subcontractor_id'), 'status' => $ctx->filter('status'), 'search' => $ctx->filter('search'),
        ]);
    }
}

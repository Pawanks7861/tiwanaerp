<?php

namespace App\Reports\Definitions;

use App\Enums\Finance\PaymentMode;
use App\Queries\Reports\CashFlowQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;

/**
 * Cash in and out in the period from approved receipts / payments, paid expenses, petty-cash
 * funding / returns and labour paid directly — never the cost ledger. The running balance
 * starts from the net of all earlier movements in scope.
 */
final class CashFlowReport extends ReportDefinition
{
    public function __construct(private readonly CashFlowQuery $cash) {}

    public function key(): string
    {
        return 'cash-flow';
    }

    public function title(): string
    {
        return 'Cash Flow';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Receipts, payments, paid expenses, petty-cash funding / returns and direct labour payments with a running balance.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return ['type', 'mode', 'party'];
    }

    public function choiceOptions(): array
    {
        return ['type' => CashFlowQuery::TYPES, 'mode' => array_column(PaymentMode::options(), 'label', 'value')];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return $this->cash->totals($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, $this->cashFilters($ctx))['count'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $filters = $this->cashFilters($ctx);
        $opening = $this->cash->opening($cid, $ctx->projectIds, (string) $ctx->from, $filters);
        $totals = $this->cash->totals($cid, $ctx->projectIds, (string) $ctx->from, $ctx->to, $filters);
        $query = $this->cash->rows($cid, $ctx->projectIds, (string) $ctx->from, $ctx->to, $filters)
            ->orderBy('txn_date')->orderBy('source')->orderBy('source_id');

        $modes = $this->choiceOptions()['mode'] + ['labour' => 'Recorded in labour payments'];
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'date' => self::day($r->txn_date),
            'type' => CashFlowQuery::TYPES[$r->type] ?? $r->type,
            'project' => $r->project_code,
            'document' => $r->document,
            'url' => $this->url($r),
            'party' => $r->party,
            'mode' => $modes[$r->mode] ?? $r->mode,
            'reference' => $r->reference,
            'inflow' => Num::dec($r->inflow)->isZero() ? null : Num::money($r->inflow),
            'outflow' => Num::dec($r->outflow)->isZero() ? null : Num::money($r->outflow),
            'balance' => $opening->plus(Num::dec($r->running))->toMoney(),
        ]);

        $monthly = $this->cash->monthly($cid, $ctx->projectIds, (string) $ctx->from, $ctx->to, $filters);
        $closing = $opening->plus($totals['net']);

        return new ReportResult(
            columns: [
                self::col('date', 'Date', 'date'),
                self::col('type', 'Type', 'status'),
                self::col('document', 'Document', 'code', ['link' => true]),
                self::col('project', 'Project', 'code', ['mobile' => false]),
                self::col('party', 'Party'),
                self::col('mode', 'Mode', 'text', ['mobile' => false]),
                self::col('reference', 'Reference', 'text', ['mobile' => false]),
                self::money('inflow', 'Inflow'),
                self::money('outflow', 'Outflow'),
                self::money('balance', 'Running balance'),
            ],
            rows: $rows,
            totals: ['date' => 'Total ('.$totals['count'].')', 'inflow' => $totals['inflow']->toMoney(), 'outflow' => $totals['outflow']->toMoney(), 'balance' => $closing->toMoney()],
            cards: [
                ['key' => 'opening', 'label' => 'Opening (earlier net)', 'value' => $opening->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'inflow', 'label' => 'Inflow', 'value' => $totals['inflow']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'success'],
                ['key' => 'outflow', 'label' => 'Outflow', 'value' => $totals['outflow']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'danger'],
                ['key' => 'net', 'label' => 'Net cash flow', 'value' => $totals['net']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'closing', 'label' => 'Closing', 'value' => $closing->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
            ],
            charts: [[
                'type' => 'bar', 'title' => 'Monthly cash flow', 'money' => true, 'sensitive' => 'financial',
                'categories' => array_map(fn ($m) => date('M Y', strtotime($m.'-01')), array_keys($monthly)),
                'series' => [
                    ['name' => 'Inflow', 'data' => array_values(array_map(fn ($m) => $m['inflow']->toMoney(), $monthly))],
                    ['name' => 'Outflow', 'data' => array_values(array_map(fn ($m) => $m['outflow']->toMoney(), $monthly))],
                    ['name' => 'Net', 'data' => array_values(array_map(fn ($m) => $m['inflow']->minus($m['outflow'])->toMoney(), $monthly))],
                ],
            ]],
            notes: [
                'Cash movements only: approved receipts / payments, paid expenses (petty-cash expenses are covered by the float funding), petty-cash funding and returns, and labour batches paid directly. Costs posted to the ledger are not cash.',
                'The running balance starts from the net of all earlier movements in scope — it is not a bank balance.',
            ],
            pagination: $pagination,
        );
    }

    /**
     * @return array{type: string|null, mode: string|null, party: string|null}
     */
    private function cashFilters(ReportContext $ctx): array
    {
        return ['type' => $ctx->filter('type'), 'mode' => $ctx->filter('mode'), 'party' => $ctx->filter('party')];
    }

    private function url(object $r): string
    {
        return match ($r->source) {
            'payment' => route('projects.payments.show', [$r->project_id, $r->source_id]),
            'expense' => route('projects.expenses.show', [$r->project_id, $r->source_id]),
            'petty_cash' => route('projects.petty-cash.show', [$r->project_id, $r->source_id]),
            default => route('projects.labour-payments.show', [$r->project_id, $r->source_id]),
        };
    }
}

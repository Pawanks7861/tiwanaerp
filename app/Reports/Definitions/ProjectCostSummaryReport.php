<?php

namespace App\Reports\Definitions;

use App\Enums\CostHead;
use App\Enums\ProjectStatus;
use App\Queries\Reports\BudgetQuery;
use App\Queries\Reports\CommittedCostQuery;
use App\Queries\Reports\CostLedgerQuery;
use App\Queries\Reports\ReceivablesQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;

/**
 * One line per project: contract value, budget, actual cost by head (cost ledger), committed,
 * billed (certified RA value before GST), received and outstanding (approved receipts), as of a
 * date. Billed − actual is the gross margin to date.
 */
final class ProjectCostSummaryReport extends ReportDefinition
{
    private const HEADS = ['material', 'labour', 'equipment', 'subcontract', 'overhead', 'other'];

    public function __construct(
        private readonly BudgetQuery $budgets,
        private readonly CostLedgerQuery $ledger,
        private readonly CommittedCostQuery $committed,
        private readonly ReceivablesQuery $receivables,
    ) {}

    public function key(): string
    {
        return 'project-cost-summary';
    }

    public function title(): string
    {
        return 'Project Cost Summary';
    }

    public function category(): string
    {
        return 'executive';
    }

    public function description(): string
    {
        return 'Portfolio view per project: contract, budget, actual cost by head, committed, billed, received and outstanding.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function filters(): array
    {
        return ['status'];
    }

    public function statusOptions(): array
    {
        return array_column(ProjectStatus::options(), 'label', 'value');
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $asOf = $ctx->asOf();
        $budget = $this->budgets->byProjectHead($cid, $ctx->projectIds);
        $actual = $this->ledger->byProjectHead($cid, $ctx->projectIds, null, $asOf);
        $committed = $this->committed->byProject($cid, $ctx->projectIds);
        $billing = $this->receivables->byProject($cid, $ctx->projectIds, $asOf);

        $keys = ['contract_value', 'budget', ...self::HEADS, 'actual', 'committed', 'billed', 'received', 'outstanding', 'margin'];
        $sum = array_fill_keys($keys, Decimal::zero());
        $rows = [];
        foreach (self::projectsInScope($ctx) as $pid => $project) {
            if ($ctx->filter('status') && $project->status !== $ctx->filter('status')) {
                continue;
            }
            $heads = $actual[$pid] ?? [];
            $figures = [
                'contract_value' => Decimal::of((string) ($project->contract_value ?? '0')),
                'budget' => Decimal::sum(array_values($budget[$pid] ?? [])),
            ];
            foreach (self::HEADS as $head) {
                $figures[$head] = $heads[$head] ?? Decimal::zero();
            }
            $figures['actual'] = Decimal::sum(array_values($heads));
            $figures['committed'] = $committed[$pid]['total'] ?? Decimal::zero();
            $figures['billed'] = $billing[$pid]['billed'] ?? Decimal::zero();
            $figures['received'] = $billing[$pid]['received'] ?? Decimal::zero();
            $figures['outstanding'] = $billing[$pid]['outstanding'] ?? Decimal::zero();
            $figures['margin'] = $figures['billed']->minus($figures['actual']);

            $row = ['project' => "{$project->code} — {$project->name}", 'status' => self::enumLabel(ProjectStatus::class, $project->status),
                'url' => route('reports.project', [$pid, 'budget-vs-actual'])];
            foreach ($keys as $key) {
                $row[$key] = $figures[$key]->toMoney();
                $sum[$key] = $sum[$key]->plus($figures[$key]);
            }
            $rows[] = $row;
        }

        $columns = [self::col('project', 'Project', 'text', ['link' => true]), self::col('status', 'Status', 'status'),
            self::money('contract_value', 'Contract value'), self::money('budget', 'Budget')];
        foreach (self::HEADS as $head) {
            $columns[] = self::money($head, CostHead::from($head)->label(), ['mobile' => false]);
        }
        array_push($columns, self::money('actual', 'Actual cost'), self::money('committed', 'Committed'), self::money('billed', 'Billed (excl. GST)'),
            self::money('received', 'Received'), self::money('outstanding', 'Outstanding'), self::money('margin', 'Billed − actual'));

        $totals = ['project' => 'Total'] + array_map(fn (Decimal $d) => $d->toMoney(), $sum);

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: $totals,
            cards: [
                ['key' => 'contract', 'label' => 'Contract value', 'value' => $totals['contract_value'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'actual', 'label' => 'Actual cost', 'value' => $totals['actual'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'billed', 'label' => 'Billed', 'value' => $totals['billed'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'outstanding', 'label' => 'Outstanding from clients', 'value' => $totals['outstanding'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: [
                'Actual = project cost ledger; billed / received from certified RA bills and approved receipts up to the as-of date; committed = open approved PO / WO value today.',
            ],
        );
    }
}

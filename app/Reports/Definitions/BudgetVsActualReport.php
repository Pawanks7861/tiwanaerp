<?php

namespace App\Reports\Definitions;

use App\Enums\CostHead;
use App\Queries\Reports\BudgetQuery;
use App\Queries\Reports\CommittedCostQuery;
use App\Queries\Reports\CostLedgerQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;

/**
 * Budget (approved budget), actual (cost ledger up to the as-of date), committed (open approved
 * PO / WO value today), variance = budget − actual, available = budget − actual − committed.
 * Company scope: one row per project; project scope: one row per cost head.
 */
final class BudgetVsActualReport extends ReportDefinition
{
    public function __construct(
        private readonly BudgetQuery $budgets,
        private readonly CostLedgerQuery $ledger,
        private readonly CommittedCostQuery $committed,
    ) {}

    public function key(): string
    {
        return 'budget-vs-actual';
    }

    public function title(): string
    {
        return 'Budget vs Actual';
    }

    public function category(): string
    {
        return 'cost';
    }

    public function description(): string
    {
        return 'Approved budget against actual cost and open commitments, with variance and available budget.';
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
        return ['head'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $head = $ctx->filter('head');
        $budget = $this->budgets->byProjectHead($cid, $ctx->projectIds);
        $actual = $this->ledger->byProjectHead($cid, $ctx->projectIds, null, $ctx->asOf());
        $committed = $this->committed->byProject($cid, $ctx->projectIds);

        $columns = [
            self::col('budget', 'Budget', 'money', ['sensitive' => 'financial']),
            self::money('actual', 'Actual'),
            self::money('committed', 'Committed'),
            self::money('variance', 'Variance (budget − actual)'),
            self::money('available', 'Available'),
            self::col('utilization', 'Utilised', 'percent'),
        ];

        $rows = [];
        $sum = ['budget' => Decimal::zero(), 'actual' => Decimal::zero(), 'committed' => Decimal::zero()];
        $line = function (Decimal $b, Decimal $a, Decimal $c) use (&$sum) {
            $sum['budget'] = $sum['budget']->plus($b);
            $sum['actual'] = $sum['actual']->plus($a);
            $sum['committed'] = $sum['committed']->plus($c);

            return self::figures($b, $a, $c);
        };

        if ($ctx->project !== null) {
            $pid = (int) $ctx->project->id;
            foreach (CostHead::cases() as $case) {
                if ($head !== null && $case->value !== $head) {
                    continue;
                }
                $b = $budget[$pid][$case->value] ?? Decimal::zero();
                $a = $actual[$pid][$case->value] ?? Decimal::zero();
                $c = in_array($case->value, ['material', 'subcontract'], true) ? ($committed[$pid][$case->value] ?? Decimal::zero()) : Decimal::zero();
                $rows[] = ['label' => $case->label(), 'flag' => $b->isZero() && $a->isPositive() ? 'Unbudgeted cost' : null] + $line($b, $a, $c);
            }
            array_unshift($columns, self::col('label', 'Cost head'));
        } else {
            foreach (self::projectsInScope($ctx) as $pid => $project) {
                $b = $head ? ($budget[$pid][$head] ?? Decimal::zero()) : Decimal::sum(array_values($budget[$pid] ?? []));
                $a = $head ? ($actual[$pid][$head] ?? Decimal::zero()) : Decimal::sum(array_values($actual[$pid] ?? []));
                $c = $head ? ($committed[$pid][$head] ?? Decimal::zero()) : ($committed[$pid]['total'] ?? Decimal::zero());
                if ($b->isZero() && $a->isZero() && $c->isZero()) {
                    continue;
                }
                $rows[] = [
                    'label' => "{$project->code} — {$project->name}",
                    'url' => route('reports.project', [$pid, $this->key()]),
                    'flag' => isset($budget[$pid]) ? null : 'No approved budget',
                ] + $line($b, $a, $c);
            }
            array_unshift($columns, self::col('label', 'Project', 'text', ['link' => true]));
        }

        $totals = ['label' => 'Total'] + self::figures($sum['budget'], $sum['actual'], $sum['committed']);
        $chartRows = array_slice($rows, 0, 15);

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: $totals,
            cards: [
                ['key' => 'budget', 'label' => 'Budget', 'value' => $totals['budget'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'actual', 'label' => 'Actual cost', 'value' => $totals['actual'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'committed', 'label' => 'Committed', 'value' => $totals['committed'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'available', 'label' => 'Available', 'value' => $totals['available'], 'type' => 'money', 'sensitive' => 'financial',
                    'tone' => Decimal::of($totals['available'])->isNegative() ? 'danger' : 'success'],
            ],
            charts: [[
                'type' => 'bar', 'title' => 'Budget vs actual', 'money' => true, 'sensitive' => 'financial',
                'categories' => array_map(fn ($r) => mb_strimwidth($r['label'], 0, 28, '…'), $chartRows),
                'series' => [
                    ['name' => 'Budget', 'data' => array_column($chartRows, 'budget')],
                    ['name' => 'Actual', 'data' => array_column($chartRows, 'actual')],
                    ['name' => 'Committed', 'data' => array_column($chartRows, 'committed')],
                ],
            ]],
            notes: [
                'Actual cost is the net of the project cost ledger up to the as-of date (reversals included).',
                'Committed is the open value of approved purchase orders (not yet received, excluding GST) and billable work orders (not yet certified), as of today.',
            ],
        );
    }

    /**
     * @return array{budget: string, actual: string, committed: string, variance: string, available: string, utilization: string|null}
     */
    private static function figures(Decimal $budget, Decimal $actual, Decimal $committed): array
    {
        return [
            'budget' => $budget->toMoney(),
            'actual' => $actual->toMoney(),
            'committed' => $committed->toMoney(),
            'variance' => $budget->minus($actual)->toMoney(),
            'available' => $budget->minus($actual)->minus($committed)->toMoney(),
            'utilization' => Num::percent($actual, $budget),
        ];
    }
}

<?php

namespace App\Reports\Definitions;

use App\Enums\CostHead;
use App\Queries\Reports\CostLedgerQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;

/**
 * Actual cost posted in the period per cost head and source document type, straight from the
 * cost ledger (reversals shown separately so gross − reversals = net).
 */
final class CostByHeadReport extends ReportDefinition
{
    public const SOURCES = [
        'material_issue_item' => 'Material issues', 'material_return_item' => 'Material returns', 'labour_attendance' => 'Labour attendance',
        'equipment_usage_log' => 'Equipment usage', 'subcontractor_bill_item' => 'Subcontractor bills', 'expense' => 'Expenses',
        'vendor_bill' => 'Vendor bills (service)',
    ];

    public function __construct(private readonly CostLedgerQuery $ledger) {}

    public function key(): string
    {
        return 'cost-by-head';
    }

    public function title(): string
    {
        return 'Cost by Head';
    }

    public function category(): string
    {
        return 'cost';
    }

    public function description(): string
    {
        return 'Actual cost posted in the period by cost head and source, net of reversals, with a monthly trend.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return ['head'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $rows = [];
        $totals = ['entries' => 0, 'gross' => Decimal::zero(), 'reversals' => Decimal::zero(), 'net' => Decimal::zero()];
        foreach ($this->ledger->byHeadAndSource($cid, $ctx->projectIds, $ctx->from, $ctx->to, $ctx->filter('head')) as $row) {
            $rows[] = [
                'head' => self::enumLabel(CostHead::class, $row->cost_head),
                'source' => self::SOURCES[$row->source_type] ?? ucwords(str_replace('_', ' ', $row->source_type)),
                'entries' => (int) $row->entries,
                'gross' => Num::money($row->gross),
                'reversals' => Num::money($row->reversals),
                'net' => Num::money($row->net),
            ];
            $totals['entries'] += (int) $row->entries;
            $totals['gross'] = $totals['gross']->plus(Num::dec($row->gross));
            $totals['reversals'] = $totals['reversals']->plus(Num::dec($row->reversals));
            $totals['net'] = $totals['net']->plus(Num::dec($row->net));
        }

        $byHead = $this->ledger->byHead($cid, $ctx->projectIds, $ctx->from, $ctx->to);
        if ($ctx->filter('head')) {
            $byHead = array_intersect_key($byHead, [$ctx->filter('head') => true]);
        }
        $monthly = $this->ledger->monthlyByHead($cid, $ctx->projectIds, (string) $ctx->from, $ctx->to);
        $months = array_keys($monthly);

        return new ReportResult(
            columns: [
                self::col('head', 'Cost head'),
                self::col('source', 'Source'),
                self::col('entries', 'Entries', 'number'),
                self::money('gross', 'Posted'),
                self::money('reversals', 'Reversals'),
                self::money('net', 'Net cost'),
            ],
            rows: $rows,
            totals: ['head' => 'Total', 'entries' => $totals['entries'], 'gross' => $totals['gross']->toMoney(),
                'reversals' => $totals['reversals']->toMoney(), 'net' => $totals['net']->toMoney()],
            cards: array_values(array_map(fn ($head, $amount) => [
                'key' => $head, 'label' => self::enumLabel(CostHead::class, $head), 'value' => $amount->toMoney(), 'type' => 'money', 'sensitive' => 'financial',
            ], array_keys($byHead), $byHead)),
            charts: [
                [
                    'type' => 'donut', 'title' => 'Cost by head', 'money' => true, 'sensitive' => 'financial',
                    'categories' => array_map(fn ($h) => self::enumLabel(CostHead::class, $h), array_keys($byHead)),
                    'series' => array_map(fn (Decimal $d) => $d->toMoney(), array_values($byHead)),
                ],
                [
                    'type' => 'bar', 'stacked' => true, 'title' => 'Monthly cost', 'money' => true, 'sensitive' => 'financial',
                    'categories' => array_map(fn ($m) => date('M Y', strtotime($m.'-01')), $months),
                    'series' => array_values(array_map(fn ($head) => [
                        'name' => self::enumLabel(CostHead::class, $head),
                        'data' => array_map(fn ($m) => ($monthly[$m][$head] ?? Decimal::zero())->toMoney(), $months),
                    ], array_keys($byHead))),
                ],
            ],
            notes: ['Source: project cost ledger (signed; reversals are negative rows).'],
        );
    }
}

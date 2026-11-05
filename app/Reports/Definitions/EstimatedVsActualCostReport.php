<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqCostHeadMapper;
use App\Services\Boq\EstimatedActualCostService;

/**
 * Stage 6 — BOQ Cost Build-Up estimate vs project_cost_ledger actual.
 * Variance = Estimated − Actual (positive = favorable / under budget).
 *
 * Period mode `current`: Actual = all ledger rows up to today (full project actual).
 * Estimated is always full-project planned cost (never period-prorated).
 */
final class EstimatedVsActualCostReport extends ReportDefinition
{
    public function __construct(private readonly EstimatedActualCostService $costs) {}

    public function key(): string
    {
        return 'estimated-vs-actual-cost';
    }

    public function title(): string
    {
        return 'Estimated vs Actual Cost';
    }

    public function category(): string
    {
        return 'cost';
    }

    public function description(): string
    {
        return 'BOQ Cost Build-Up estimate against project cost ledger actual, by BOQ item and cost head. Payments and Tally are excluded.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function permissions(): array
    {
        return ['boq.view_costs'];
    }

    public function scopes(): array
    {
        return ['project'];
    }

    public function periodMode(): string
    {
        return 'current';
    }

    public function filters(): array
    {
        return ['head'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        abort_unless($ctx->project !== null, 404);

        $headFilter = $ctx->filter('head');
        $displayHead = $headFilter ? BoqCostHeadMapper::fromLedgerHead((string) $headFilter) : null;

        $data = $this->costs->projectReport($ctx->project, [
            'from' => null,
            'to' => $ctx->to,
            'cost_head' => $displayHead,
        ]);
        $totals = $data['totals'];

        $columns = [
            self::col('section', 'BOQ Head', 'text'),
            self::col('item', 'BOQ Item', 'text', ['link' => true]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('boq_qty', 'BOQ Qty', 'qty', ['mobile' => false]),
            self::col('cost_rate', 'Cost Rate', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::money('estimated_cost', 'Estimated'),
            self::money('actual_cost', 'Actual'),
            self::money('variance', 'Variance (Est − Act)'),
            self::col('variance_percent', 'Var %', 'percent', ['sensitive' => 'financial']),
            self::col('utilization_percent', 'Util %', 'percent', ['sensitive' => 'financial']),
            self::col('status_label', 'Status', 'status'),
        ];

        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[] = [
                'section' => trim(($row['section_code'] ?? '').' '.($row['section_name'] ?? '')) ?: '—',
                'item' => trim(($row['item_code'] ? $row['item_code'].' — ' : '').($row['item_name'] ?? '')),
                'url' => $row['boq_id'] && $row['boq_item_id']
                    ? route('projects.boqs.items.workspace', [$ctx->project->id, $row['boq_id'], $row['boq_item_id'], 'cost' => 1])
                    : null,
                'unit' => $row['unit'] ?? '—',
                'boq_qty' => $row['boq_qty'],
                'cost_rate' => $row['cost_rate'],
                'estimated_cost' => $row['estimated_cost'],
                'actual_cost' => $row['actual_cost'],
                'variance' => $row['variance'],
                'variance_percent' => $row['variance_percent'],
                'utilization_percent' => $row['utilization_percent'],
                'status_label' => match ($row['status']) {
                    'over' => 'Over budget',
                    'near' => 'Near estimate',
                    default => 'Under budget',
                },
                'flag' => ! empty($row['orphan']) ? 'Actual linked to BOQ line without current estimate' : null,
            ];
        }

        $headColumns = [
            self::col('label', 'Cost head'),
            self::money('estimated_cost', 'Estimated'),
            self::money('actual_cost', 'Actual'),
            self::money('variance', 'Variance'),
        ];
        $headRows = array_map(fn ($h) => [
            'label' => $h['label'],
            'estimated_cost' => $h['estimated'],
            'actual_cost' => $h['actual'],
            'variance' => $h['variance'],
        ], $data['head_summary']);

        $notes = [$data['formula_note']];
        $notes[] = 'Actual includes all project_cost_ledger postings through today (signed; reversals reduce actual).';
        if ($data['unallocated']['has_unallocated']) {
            $notes[] = 'Some project costs are not linked to BOQ items (Unallocated Actual: '.$data['unallocated']['total'].').';
        }
        if ($totals['reconciles']) {
            $notes[] = 'Actual total reconciles exactly to project_cost_ledger ('.$totals['ledger_total'].').';
        } else {
            $notes[] = 'Warning: linked + unallocated actual does not equal ledger total.';
        }

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: [
                'section' => 'Total',
                'item' => '',
                'estimated_cost' => $totals['estimated_cost'],
                'actual_cost' => $totals['actual_cost'],
                'variance' => $totals['variance'],
                'variance_percent' => $totals['variance_percent'],
                'utilization_percent' => $totals['utilization_percent'],
            ],
            cards: [
                ['key' => 'estimated', 'label' => 'Estimated', 'value' => $totals['estimated_cost'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'actual', 'label' => 'Actual', 'value' => $totals['actual_cost'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'variance', 'label' => 'Variance', 'value' => $totals['variance'], 'type' => 'money', 'sensitive' => 'financial', 'hint' => 'Estimated − Actual'],
                ['key' => 'utilization', 'label' => 'Utilization', 'value' => $totals['utilization_percent'] !== null ? $totals['utilization_percent'].'%' : '—', 'type' => 'text', 'sensitive' => 'financial'],
                ['key' => 'unallocated', 'label' => 'Unallocated actual', 'value' => $data['unallocated']['total'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: $notes,
            sections: [
                [
                    'title' => 'Cost head summary',
                    'columns' => $headColumns,
                    'rows' => $headRows,
                    'sensitive' => 'financial',
                ],
            ],
        );
    }
}

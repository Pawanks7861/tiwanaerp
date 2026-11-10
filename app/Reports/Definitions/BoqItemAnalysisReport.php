<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;
use App\Services\Boq\EffectiveBoqQuantityService;

final class BoqItemAnalysisReport extends ReportDefinition
{
    public function __construct(
        private readonly BoqIntelligenceService $intel,
        private readonly EffectiveBoqQuantityService $effectiveQty,
    ) {}

    public function key(): string
    {
        return 'boq-item-analysis';
    }

    public function title(): string
    {
        return 'BOQ Item Analysis';
    }

    public function category(): string
    {
        return 'project';
    }

    public function description(): string
    {
        return 'Original vs effective quantity, progress, billing, cost and variation status per BOQ item.';
    }

    public function permissions(): array
    {
        return ['boq.view'];
    }

    public function scopes(): array
    {
        return ['project'];
    }

    public function periodMode(): string
    {
        return 'current';
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        abort_unless($ctx->project !== null, 404);
        $canCosts = $ctx->user->can('boq.view_costs') && $ctx->user->can('reports.view_financial');
        $data = $this->intel->itemAnalysis($ctx->project, $canCosts);

        $columns = [
            self::col('section', 'Head', 'text', ['mobile' => false]),
            self::col('item', 'Item', 'text', ['link' => true]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('quantity_source', 'Qty source', 'text', ['mobile' => false]),
            self::col('original_qty', 'Original Qty', 'qty'),
            self::col('variation_qty', 'VO Qty', 'qty', ['mobile' => false]),
            self::col('effective_qty', 'Effective Qty', 'qty'),
            self::col('executed_qty', 'Executed', 'qty'),
            self::col('remaining_qty', 'Remaining', 'qty', ['mobile' => false]),
            self::col('billed_qty', 'Billed', 'qty', ['mobile' => false]),
            self::col('executed_unbilled_qty', 'Exec. unbilled', 'qty', ['mobile' => false]),
            self::col('progress_percent', 'Progress %', 'percent'),
            self::col('selling_rate', 'Sell rate', 'rate', ['mobile' => false]),
            self::money('effective_revenue', 'Effective revenue'),
            self::col('cost_rate', 'Cost rate', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::money('estimated_cost', 'Est. cost'),
            self::money('actual_cost', 'Actual cost'),
            self::money('cost_variance', 'Cost variance'),
            self::col('material_requirement', 'Materials', 'text', ['mobile' => false]),
            self::col('bbs_status', 'BBS', 'text', ['mobile' => false]),
            self::col('variation_count', 'VO count', 'text', ['mobile' => false]),
        ];

        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                'section' => $r['section'],
                'item' => trim(($r['item_code'] ? $r['item_code'].' — ' : '').$r['name']),
                'url' => $r['boq_id'] && $r['boq_item_id']
                    ? route('projects.boqs.items.workspace', [$ctx->project->id, $r['boq_id'], $r['boq_item_id']], absolute: false)
                    : null,
                'unit' => $r['unit'],
                'quantity_source' => $r['quantity_source'],
                'original_qty' => $r['original_qty'],
                'variation_qty' => $r['variation_qty'],
                'effective_qty' => $r['effective_qty'],
                'executed_qty' => $r['executed_qty'],
                'remaining_qty' => $r['remaining_qty'],
                'billed_qty' => $r['billed_qty'],
                'executed_unbilled_qty' => $r['executed_unbilled_qty'],
                'progress_percent' => $r['progress_percent'],
                'selling_rate' => $r['selling_rate'],
                'effective_revenue' => $r['effective_revenue'] ?? null,
                'cost_rate' => $r['cost_rate'] ?? null,
                'estimated_cost' => $r['estimated_cost'] ?? null,
                'actual_cost' => $r['actual_cost'] ?? null,
                'cost_variance' => $r['cost_variance'] ?? null,
                'material_requirement' => $r['material_requirement'],
                'bbs_status' => $r['bbs_status'],
                'variation_count' => (string) $r['variation_count'],
            ];
        }

        $contract = $this->effectiveQty->contractValueSummary($ctx->project);

        return new ReportResult(
            columns: $columns,
            rows: $paginate ? array_slice($rows, 0, 500) : $rows,
            cards: [
                ['key' => 'original', 'label' => 'Original contract', 'value' => $contract['original_contract_value'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'vo', 'label' => 'Approved variations', 'value' => $contract['approved_variation_value'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'effective', 'label' => 'Effective contract', 'value' => $contract['effective_contract_value'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'pending', 'label' => 'Pending variations', 'value' => $contract['pending_variation_value'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: [
                'Effective Qty = Original approved qty + approved variation deltas. Draft/submitted variations excluded.',
                'Variation-originated extra items appear once (materialized BOQ line); contract deltas use variation revenue_impact.',
            ],
        );
    }
}

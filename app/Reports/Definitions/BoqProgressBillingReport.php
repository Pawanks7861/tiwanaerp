<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;

final class BoqProgressBillingReport extends ReportDefinition
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function key(): string
    {
        return 'boq-progress-billing';
    }

    public function title(): string
    {
        return 'BOQ Progress vs Billing';
    }

    public function category(): string
    {
        return 'progress';
    }

    public function description(): string
    {
        return 'Effective quantity vs executed progress vs certified RA billed quantity. Draft RA excluded.';
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
        $data = $this->intel->progressVsBilling($ctx->project, $canCosts);

        $columns = [
            self::col('item', 'BOQ Item', 'text', ['link' => true]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('effective_qty', 'Effective Qty', 'qty'),
            self::col('executed_qty', 'Executed', 'qty'),
            self::col('progress_percent', 'Progress %', 'percent'),
            self::col('billed_qty', 'Billed (certified)', 'qty'),
            self::col('executed_unbilled', 'Executed unbilled', 'qty'),
            self::col('remaining_execution', 'Remaining exec.', 'qty', ['mobile' => false]),
            self::col('selling_rate', 'Sell rate', 'rate', ['mobile' => false]),
            self::money('effective_value', 'Effective value'),
            self::money('billed_value', 'Billed value'),
            self::money('remaining_billable_value', 'Remaining billable'),
        ];

        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                'item' => trim(($r['item_code'] ? $r['item_code'].' — ' : '').$r['name']),
                'url' => route('projects.boqs.items.workspace', [$ctx->project->id, $r['boq_id'], $r['boq_item_id']], absolute: false),
                'unit' => $r['unit'],
                'effective_qty' => $r['effective_qty'],
                'executed_qty' => $r['executed_qty'],
                'progress_percent' => $r['progress_percent'],
                'billed_qty' => $r['billed_qty'],
                'executed_unbilled' => $r['executed_unbilled'],
                'remaining_execution' => $r['remaining_execution'],
                'selling_rate' => $r['selling_rate'],
                'effective_value' => $r['effective_value'] ?? null,
                'billed_value' => $r['billed_value'] ?? null,
                'remaining_billable_value' => $r['remaining_billable_value'] ?? null,
            ];
        }

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            notes: ['Remaining execution = Effective − Executed. Executed unbilled = Executed − Certified billed. Anomalies are not hidden.'],
        );
    }
}

<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;

final class BoqCostBuildUpReport extends ReportDefinition
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function key(): string
    {
        return 'boq-cost-buildup';
    }

    public function title(): string
    {
        return 'BOQ Cost Build-Up Report';
    }

    public function category(): string
    {
        return 'cost';
    }

    public function description(): string
    {
        return 'Per-item cost head rates, selling rate, margin and estimated cost at effective quantity.';
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

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        abort_unless($ctx->project !== null, 404);
        $data = $this->intel->costBuildUpReport($ctx->project);

        $columns = [
            self::col('item', 'BOQ Item', 'text', ['link' => true]),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('effective_qty', 'Effective Qty', 'qty', ['mobile' => false]),
            self::col('material_rate', 'Material / unit', 'rate', ['sensitive' => 'financial']),
            self::col('labour_rate', 'Labour / unit', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::col('equipment_rate', 'Equipment / unit', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::col('subcontract_rate', 'Subcontract / unit', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::col('other_rate', 'Other / unit', 'rate', ['sensitive' => 'financial', 'mobile' => false]),
            self::col('cost_rate', 'Cost rate', 'rate', ['sensitive' => 'financial']),
            self::col('selling_rate', 'Selling rate', 'rate', ['sensitive' => 'financial']),
            self::col('margin_unit', 'Margin / unit', 'rate', ['sensitive' => 'financial']),
            self::money('estimated_cost', 'Estimated cost'),
            self::money('estimated_margin', 'Estimated margin'),
        ];

        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                ...$r,
                'url' => route('projects.boqs.items.workspace', [$ctx->project->id, $r['boq_id'], $r['boq_item_id']], absolute: false).'?cost=1',
            ];
        }

        return new ReportResult(columns: $columns, rows: $rows);
    }
}

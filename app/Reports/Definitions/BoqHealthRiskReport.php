<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Services\Boq\BoqIntelligenceService;

final class BoqHealthRiskReport extends ReportDefinition
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function key(): string
    {
        return 'boq-health-risk';
    }

    public function title(): string
    {
        return 'BOQ Health & Risk';
    }

    public function category(): string
    {
        return 'executive';
    }

    public function description(): string
    {
        return 'Rule-based cost, quantity and material risk flags. Transparent thresholds only — no opaque scoring.';
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
        $health = $this->intel->healthCounts($ctx->project, null, $canCosts);
        $risk = $this->intel->riskFlags($ctx->project, $canCosts);

        $columns = [
            self::col('domain', 'Domain'),
            self::col('item', 'Item'),
            self::col('flag', 'Flag'),
            self::col('detail', 'Detail', 'text', ['mobile' => false]),
        ];

        $rows = [];
        foreach (['cost' => $risk['cost'], 'quantity' => $risk['quantity'], 'material' => $risk['material']] as $domain => $list) {
            foreach ($list as $r) {
                $detail = collect($r)->except(['item', 'flag'])->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ');
                $rows[] = [
                    'domain' => ucfirst($domain),
                    'item' => $r['item'] ?? '',
                    'flag' => $r['flag'] ?? '',
                    'detail' => $detail,
                ];
            }
        }

        $cards = [
            ['key' => 'over_cost', 'label' => 'Over-cost items', 'value' => (string) $health['over_cost_items'], 'type' => 'text'],
            ['key' => 'over_issued', 'label' => 'Over-issued materials', 'value' => (string) $health['over_issued_materials'], 'type' => 'text'],
            ['key' => 'pending_vo', 'label' => 'Pending variations', 'value' => (string) $health['pending_variations'], 'type' => 'text'],
            ['key' => 'pending_meas', 'label' => 'Pending measurements', 'value' => (string) $health['pending_measurements'], 'type' => 'text'],
            ['key' => 'unmapped', 'label' => 'Unmapped BBS', 'value' => (string) $health['unmapped_bbs_steel'], 'type' => 'text'],
            ['key' => 'unbilled', 'label' => 'Executed unbilled items', 'value' => (string) $health['executed_unbilled_items'], 'type' => 'text'],
            ['key' => 'unalloc', 'label' => 'Unallocated cost flag', 'value' => (string) $health['unallocated_project_costs'], 'type' => 'text'],
        ];

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            cards: $cards,
            notes: $rows === [] ? ['No risk flags for current project data.'] : [],
        );
    }
}

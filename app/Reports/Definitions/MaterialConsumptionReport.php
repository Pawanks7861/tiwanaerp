<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\MaterialConsumptionQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MaterialConsumptionReport extends ReportDefinition
{
    public function __construct(private readonly MaterialConsumptionQuery $consumption) {}

    public function key(): string
    {
        return 'material-consumption';
    }

    public function title(): string
    {
        return 'Material Consumption';
    }

    public function category(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Material issued to site (net of site returns) against usage recorded in approved site diaries.';
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function filters(): array
    {
        return ['material', 'search'];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return DB::query()->fromSub($this->query($ctx), 'x')->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $query = DB::query()->fromSub($this->query($ctx), 'c')->orderBy('project_code')->orderBy('material')->orderBy('material_id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'project' => $r->project_code,
            'material' => trim("{$r->material_code} — {$r->material}", ' —'),
            'unit' => $r->unit,
            'issued' => Num::qty($r->issued),
            'returned' => Num::qty($r->returned),
            'net_issued' => Num::qty($r->net_issued),
            'diary' => Num::qty($r->diary),
            'variance' => Num::qty($r->variance),
            'variance_pct' => Num::percent($r->variance, $r->net_issued),
            'net_value' => Num::money($r->net_value),
            'flag' => Num::dec($r->diary)->greaterThan(Num::dec($r->net_issued)) ? 'Diary usage exceeds issues' : null,
        ]);
        $value = DB::query()->fromSub($this->query($ctx), 't')->sum('net_value');

        return new ReportResult(
            columns: [
                self::col('material', 'Material'),
                self::col('project', 'Project', 'code'),
                self::col('unit', 'Unit', 'text', ['mobile' => false]),
                self::col('issued', 'Issued', 'qty'),
                self::col('returned', 'Returned', 'qty', ['mobile' => false]),
                self::col('net_issued', 'Net issued', 'qty'),
                self::col('diary', 'Diary usage', 'qty'),
                self::col('variance', 'Variance', 'qty'),
                self::col('variance_pct', 'Variance %', 'percent', ['mobile' => false]),
                self::col('net_value', 'Net issue value', 'money', ['sensitive' => 'valuation', 'mobile' => false]),
            ],
            rows: $rows,
            totals: ['material' => 'Total', 'net_value' => Num::money($value)],
            cards: [['key' => 'value', 'label' => 'Net issue value', 'value' => Num::money($value), 'type' => 'money', 'sensitive' => 'valuation']],
            notes: [
                'Issues and site returns are approved inventory documents; diary usage comes from approved site diaries and is informational only — it never moves stock or cost.',
                'Variance = net issued − diary usage (positive: issued but not reported as used).',
            ],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->consumption->base($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, [
            'material_id' => $ctx->filter('material_id'), 'search' => $ctx->filter('search'),
        ]);
    }
}

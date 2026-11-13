<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapBalanceReport extends ReportDefinition
{
    public function key(): string { return 'scrap-balance'; }
    public function title(): string { return 'Scrap Balance'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Available scrap quantity by project, category and type.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function periodMode(): string { return 'asof'; }
    public function filters(): array { return ['project', 'category', 'search']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = DB::table('scrap_balances as b')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->join('scrap_types as t', 't.id', '=', 'b.scrap_type_id')
            ->leftJoin('scrap_categories as c', 'c.id', '=', 't.scrap_category_id')
            ->join('units as u', 'u.id', '=', 'b.unit_id')
            ->where('b.company_id', $ctx->companyId())
            ->whereIn('b.project_id', $ctx->projectIds)
            ->where('b.quantity', '>', 0)
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('b.project_id', $id))
            ->when($ctx->filter('category'), fn ($q, $id) => $q->where('t.scrap_category_id', $id))
            ->when($ctx->filter('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('t.name', 'like', "%{$s}%")->orWhere('t.code', 'like', "%{$s}%")))
            ->selectRaw('p.name as project, c.name as category, t.name as scrap_type, u.symbol as unit, b.quantity, b.qty_generated, b.qty_dispatched, b.qty_adjusted_net, b.last_movement_at');

        $count = (clone $base)->count();
        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('project', 'Project'),
                self::col('category', 'Category'),
                self::col('scrap_type', 'Scrap type'),
                self::col('unit', 'Unit'),
                self::col('quantity', 'Available', 'number'),
                self::col('qty_generated', 'Generated', 'number'),
                self::col('qty_dispatched', 'Dispatched', 'number'),
                self::col('qty_adjusted_net', 'Adjusted net', 'number'),
                self::col('last_movement_at', 'Last movement', 'datetime'),
            ],
            rows: $rows,
            cards: [['key' => 'lines', 'label' => 'Balance lines', 'value' => $count, 'type' => 'number']],
            pagination: $pagination,
        );
    }
}

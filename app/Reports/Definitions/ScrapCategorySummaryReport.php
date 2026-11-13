<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapCategorySummaryReport extends ReportDefinition
{
    public function key(): string { return 'scrap-category-summary'; }
    public function title(): string { return 'Scrap Category Summary'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Available scrap quantity rolled up by category.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function periodMode(): string { return 'asof'; }
    public function filters(): array { return ['project']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = DB::table('scrap_balances as b')
            ->join('scrap_types as t', 't.id', '=', 'b.scrap_type_id')
            ->leftJoin('scrap_categories as c', 'c.id', '=', 't.scrap_category_id')
            ->where('b.company_id', $ctx->companyId())
            ->whereIn('b.project_id', $ctx->projectIds)
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('b.project_id', $id))
            ->groupBy('c.id', 'c.name')
            ->selectRaw("coalesce(c.name, 'Uncategorised') as category, sum(b.quantity) as available_qty, count(*) as type_lines");

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('category', 'Category'),
                self::col('available_qty', 'Available qty', 'number'),
                self::col('type_lines', 'Balance lines', 'number'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

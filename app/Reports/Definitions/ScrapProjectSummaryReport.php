<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapProjectSummaryReport extends ReportDefinition
{
    public function key(): string { return 'scrap-project-summary'; }
    public function title(): string { return 'Scrap Project Summary'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Per-project scrap balances and movement totals.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function periodMode(): string { return 'asof'; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = DB::table('scrap_balances as b')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->where('b.company_id', $ctx->companyId())
            ->whereIn('b.project_id', $ctx->projectIds)
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.name as project, sum(b.quantity) as available, sum(b.qty_generated) as generated, sum(b.qty_dispatched) as dispatched');

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('project', 'Project'),
                self::col('available', 'Available', 'number'),
                self::col('generated', 'Generated (life)', 'number'),
                self::col('dispatched', 'Dispatched (life)', 'number'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

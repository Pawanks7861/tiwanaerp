<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapGenerationRegisterReport extends ReportDefinition
{
    public function key(): string { return 'scrap-generation-register'; }
    public function title(): string { return 'Scrap Generation Register'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Scrap generation documents with line quantities.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function filters(): array { return ['project', 'status', 'search']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = DB::table('scrap_generations as g')
            ->join('projects as p', 'p.id', '=', 'g.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'g.created_by')
            ->leftJoin(DB::raw('(select scrap_generation_id, sum(quantity) as total_qty from scrap_generation_items group by scrap_generation_id) gi'), 'gi.scrap_generation_id', '=', 'g.id')
            ->where('g.company_id', $ctx->companyId())
            ->whereIn('g.project_id', $ctx->projectIds)
            ->whereBetween('g.generation_date', [$ctx->from, $ctx->to])
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('g.project_id', $id))
            ->when($ctx->filter('status'), fn ($q, $s) => $q->where('g.status', $s))
            ->when($ctx->filter('search'), fn ($q, $s) => $q->where('g.generation_number', 'like', "%{$s}%"))
            ->selectRaw('g.generation_number, g.generation_date, p.name as project, g.status, gi.total_qty, g.source_type, u.name as created_by');

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('generation_number', 'Document'),
                self::col('generation_date', 'Date', 'date'),
                self::col('project', 'Project'),
                self::col('status', 'Status'),
                self::col('total_qty', 'Total qty', 'number'),
                self::col('source_type', 'Source'),
                self::col('created_by', 'Created by'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

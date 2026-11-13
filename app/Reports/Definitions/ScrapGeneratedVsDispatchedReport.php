<?php

namespace App\Reports\Definitions;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapGeneratedVsDispatchedReport extends ReportDefinition
{
    public function key(): string { return 'scrap-generated-vs-dispatched'; }
    public function title(): string { return 'Scrap Generated vs Dispatched'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Monthly scrap generation in vs gate dispatch out by scrap type.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function filters(): array { return ['project']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $in = ScrapLedgerTxnType::GenerationIn->value;
        $out = ScrapLedgerTxnType::GatePassOut->value;

        $base = DB::table('scrap_ledger_entries as l')
            ->join('scrap_types as t', 't.id', '=', 'l.scrap_type_id')
            ->where('l.company_id', $ctx->companyId())
            ->whereIn('l.project_id', $ctx->projectIds)
            ->whereBetween('l.transaction_date', [$ctx->from, $ctx->to])
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('l.project_id', $id))
            ->whereIn('l.transaction_type', [$in, $out])
            ->groupBy('t.id', 't.name', DB::raw("date_format(l.transaction_date, '%Y-%m')"))
            ->selectRaw("date_format(l.transaction_date, '%Y-%m') as month, t.name as scrap_type, sum(case when l.transaction_type = '{$in}' then l.quantity_in else 0 end) as generated, sum(case when l.transaction_type = '{$out}' then l.quantity_out else 0 end) as dispatched");

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('month', 'Month'),
                self::col('scrap_type', 'Scrap type'),
                self::col('generated', 'Generated', 'number'),
                self::col('dispatched', 'Dispatched', 'number'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

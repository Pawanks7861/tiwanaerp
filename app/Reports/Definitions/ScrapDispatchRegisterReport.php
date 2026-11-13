<?php

namespace App\Reports\Definitions;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapDispatchRegisterReport extends ReportDefinition
{
    public function key(): string { return 'scrap-dispatch-register'; }
    public function title(): string { return 'Scrap Dispatch Register'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Scrap dispatched on outbound gate passes (ledger gate_pass_out).'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export']; }
    public function filters(): array { return ['project', 'search']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $type = ScrapLedgerTxnType::GatePassOut->value;
        $base = DB::table('scrap_ledger_entries as l')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->join('scrap_types as t', 't.id', '=', 'l.scrap_type_id')
            ->join('units as u', 'u.id', '=', 'l.unit_id')
            ->where('l.company_id', $ctx->companyId())
            ->whereIn('l.project_id', $ctx->projectIds)
            ->where('l.transaction_type', $type)
            ->whereBetween('l.transaction_date', [$ctx->from, $ctx->to])
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('l.project_id', $id))
            ->when($ctx->filter('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('l.reference_number', 'like', "%{$s}%")->orWhere('t.name', 'like', "%{$s}%")))
            ->selectRaw('l.transaction_date, l.reference_number as gate_pass, p.name as project, t.name as scrap_type, u.symbol as unit, l.quantity_out as quantity, l.remarks');

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('transaction_date', 'Date', 'date'),
                self::col('gate_pass', 'Gate pass'),
                self::col('project', 'Project'),
                self::col('scrap_type', 'Scrap type'),
                self::col('unit', 'Unit'),
                self::col('quantity', 'Qty out', 'number'),
                self::col('remarks', 'Remarks'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

<?php

namespace App\Reports\Definitions;

use App\Enums\Gate\GatePassItemType;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class ScrapGatePassMovementReport extends ReportDefinition
{
    public function key(): string { return 'scrap-gate-pass-movement'; }
    public function title(): string { return 'Scrap Gate Pass Movement'; }
    public function category(): string { return 'scrap'; }
    public function description(): string { return 'Scrap lines on gate passes with direction and status.'; }
    public function permissions(): array { return ['scrap.view', 'scrap.export', 'gate_pass.view']; }
    public function filters(): array { return ['project', 'search']; }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $scrap = GatePassItemType::Scrap->value;
        $base = DB::table('gate_pass_items as i')
            ->join('gate_passes as g', 'g.id', '=', 'i.gate_pass_id')
            ->join('projects as p', 'p.id', '=', 'g.project_id')
            ->leftJoin('scrap_types as t', 't.id', '=', 'i.scrap_type_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->where('g.company_id', $ctx->companyId())
            ->whereIn('g.project_id', $ctx->projectIds)
            ->where('i.item_type', $scrap)
            ->whereBetween('g.passed_at', [$ctx->from.' 00:00:00', $ctx->to.' 23:59:59'])
            ->when($ctx->filter('project_id'), fn ($q, $id) => $q->where('g.project_id', $id))
            ->when($ctx->filter('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('g.pass_number', 'like', "%{$s}%")->orWhere('i.description', 'like', "%{$s}%")))
            ->selectRaw('g.pass_number, g.passed_at, g.direction, g.status, p.name as project, coalesce(t.name, i.scrap_type) as scrap_type, i.quantity, u.symbol as unit, i.description');

        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('pass_number', 'Gate pass'),
                self::col('passed_at', 'Date', 'datetime'),
                self::col('direction', 'Direction'),
                self::col('status', 'Status'),
                self::col('project', 'Project'),
                self::col('scrap_type', 'Scrap type'),
                self::col('quantity', 'Qty', 'number'),
                self::col('unit', 'Unit'),
                self::col('description', 'Description'),
            ],
            rows: $rows,
            pagination: $pagination,
        );
    }
}

<?php

namespace App\Reports\Definitions;

use App\Enums\Gate\GatePassReturnStatus;
use App\Enums\Gate\GatePassStatus;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

final class RgpOutstandingReport extends ReportDefinition
{
    public function key(): string
    {
        return 'rgp-outstanding';
    }

    public function title(): string
    {
        return 'RGP Outstanding';
    }

    public function category(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Returnable gate passes with pending return quantity, including overdue days.';
    }

    public function permissions(): array
    {
        return ['gate_pass.view'];
    }

    public function filters(): array
    {
        return ['search', 'overdue'];
    }

    public function choiceOptions(): array
    {
        return ['overdue' => ['1' => 'Overdue only', '0' => 'All outstanding']];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $today = $ctx->today->toDateString();
        $base = DB::table('gate_passes as gp')
            ->join('projects as p', 'p.id', '=', 'gp.project_id')
            ->join('gate_pass_items as gpi', 'gpi.gate_pass_id', '=', 'gp.id')
            ->leftJoin('users as u', 'u.id', '=', 'gp.created_by')
            ->leftJoin(DB::raw('(select gate_pass_item_id, sum(quantity) as returned_qty from gate_pass_return_items gri join gate_pass_returns gr on gr.id = gri.gate_pass_return_id where gr.status = \''.GatePassReturnStatus::Verified->value.'\' group by gate_pass_item_id) r'), 'r.gate_pass_item_id', '=', 'gpi.id')
            ->where('gp.company_id', $ctx->companyId())
            ->whereIn('gp.project_id', $ctx->projectIds)
            ->where('gp.returnable', true)
            ->whereIn('gp.status', [GatePassStatus::Verified->value, GatePassStatus::Approved->value])
            ->whereRaw('(gpi.quantity - coalesce(r.returned_qty, 0)) > 0')
            ->when($ctx->filter('overdue') === '1', fn ($q) => $q->whereNotNull('gp.expected_return_date')->whereDate('gp.expected_return_date', '<', $today))
            ->when($ctx->filter('search'), fn ($q, $s) => $q->where(function ($w) use ($s) {
                $w->where('gp.pass_number', 'like', "%{$s}%")->orWhere('gpi.description', 'like', "%{$s}%");
            }))
            ->selectRaw('gp.id, gp.pass_number, gp.passed_at, gp.expected_return_date, p.name as project, gpi.description as item, gpi.quantity as qty_sent, coalesce(r.returned_qty,0) as qty_returned, (gpi.quantity - coalesce(r.returned_qty,0)) as qty_pending, u.name as requested_by, gp.status, case when gp.expected_return_date is not null and gp.expected_return_date < ? then datediff(?, gp.expected_return_date) else 0 end as days_overdue', [$today, $today]);

        $count = (clone $base)->count();
        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => [
            'pass_number' => $r->pass_number,
            'project' => $r->project,
            'request_date' => $r->passed_at,
            'expected_return_date' => $r->expected_return_date,
            'item' => $r->item,
            'qty_sent' => $r->qty_sent,
            'qty_returned' => $r->qty_returned,
            'qty_pending' => $r->qty_pending,
            'days_overdue' => (int) $r->days_overdue,
            'requested_by' => $r->requested_by,
            'status' => $r->status,
        ]);

        return new ReportResult(
            columns: [
                self::col('pass_number', 'Gate pass'),
                self::col('project', 'Project'),
                self::col('request_date', 'Request date', 'datetime'),
                self::col('expected_return_date', 'Expected return', 'date'),
                self::col('item', 'Item'),
                self::col('qty_sent', 'Sent', 'number'),
                self::col('qty_returned', 'Returned', 'number'),
                self::col('qty_pending', 'Pending', 'number'),
                self::col('days_overdue', 'Days overdue', 'number'),
                self::col('requested_by', 'Requested by'),
                self::col('status', 'Status'),
            ],
            rows: $rows,
            cards: [['key' => 'lines', 'label' => 'Outstanding lines', 'value' => $count, 'type' => 'number']],
            notes: ['Pending = sent − verified returns. Closed RGPs are excluded.'],
            pagination: $pagination,
        );
    }
}

<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\CostLedgerQuery;
use App\Queries\Reports\PayablesQuery;
use App\Queries\Reports\ResourceCostQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Approved attendance per labourer (days, OT, wages) in the period. Labour cost reconciles to
 * the cost ledger's labour head; labour payables come from approved payment batches less
 * approved payments (attendance alone is never a payable).
 */
final class LabourCostReport extends ReportDefinition
{
    public function __construct(
        private readonly ResourceCostQuery $resources,
        private readonly CostLedgerQuery $ledger,
        private readonly PayablesQuery $payables,
    ) {}

    public function key(): string
    {
        return 'labour-cost';
    }

    public function title(): string
    {
        return 'Labour Cost & Attendance';
    }

    public function category(): string
    {
        return 'labour';
    }

    public function description(): string
    {
        return 'Approved attendance per labourer: days present, half days, OT and wages, reconciled to ledger labour cost.';
    }

    public function permissions(): array
    {
        return ['labour.view'];
    }

    public function filters(): array
    {
        return ['subcontractor', 'search'];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return DB::query()->fromSub($this->query($ctx), 'x')->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $base = $this->query($ctx);
        $sum = DB::query()->fromSub(clone $base, 't')->selectRaw('COUNT(*) as cnt, SUM(present) as present, SUM(half_day) as half_day, SUM(absent) as absent,
            SUM(on_leave) as on_leave, SUM(ot_hours) as ot_hours, SUM(wages) as wages, SUM(ot_amount) as ot_amount, SUM(total_cost) as total_cost')->first();

        $query = DB::query()->fromSub($base, 'a')->orderBy('project_code')->orderBy('labour')->orderBy('labour_id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'project' => $r->project_code,
            'labour' => trim("{$r->labour_code} — {$r->labour}", ' —'),
            'trade' => $r->trade,
            'subcontractor' => $r->subcontractor,
            'present' => (int) $r->present,
            'half_day' => (int) $r->half_day,
            'absent' => (int) $r->absent,
            'on_leave' => (int) $r->on_leave,
            'man_days' => Num::dec($r->present)->plus(Num::dec($r->half_day)->dividedBy(2))->round(1)->toString(),
            'ot_hours' => Num::qty($r->ot_hours),
            'wages' => Num::money($r->wages),
            'ot_amount' => Num::money($r->ot_amount),
            'total_cost' => Num::money($r->total_cost),
        ]);

        $ledgerLabour = $this->ledger->byHead($cid, $ctx->projectIds, $ctx->from, $ctx->to)['labour'];
        $outstanding = $this->payables->outstandingByKind($cid, $ctx->projectIds, $ctx->asOf())['labour'];
        $manDays = Num::dec($sum->present ?? null)->plus(Num::dec($sum->half_day ?? null)->dividedBy(2))->round(1)->toString();

        return new ReportResult(
            columns: [
                self::col('labour', 'Labourer'),
                self::col('project', 'Project', 'code'),
                self::col('trade', 'Trade', 'text', ['mobile' => false]),
                self::col('subcontractor', 'Subcontractor', 'text', ['mobile' => false]),
                self::col('present', 'Present', 'number'),
                self::col('half_day', 'Half days', 'number', ['mobile' => false]),
                self::col('absent', 'Absent', 'number', ['mobile' => false]),
                self::col('on_leave', 'Leave', 'number', ['mobile' => false]),
                self::col('man_days', 'Man-days', 'number'),
                self::col('ot_hours', 'OT hours', 'qty', ['mobile' => false]),
                self::money('wages', 'Wages', ['mobile' => false]),
                self::money('ot_amount', 'OT amount', ['mobile' => false]),
                self::money('total_cost', 'Labour cost'),
            ],
            rows: $rows,
            totals: ['labour' => 'Total ('.(int) ($sum->cnt ?? 0).')', 'present' => (int) ($sum->present ?? 0), 'half_day' => (int) ($sum->half_day ?? 0),
                'absent' => (int) ($sum->absent ?? 0), 'on_leave' => (int) ($sum->on_leave ?? 0), 'man_days' => $manDays, 'ot_hours' => Num::qty($sum->ot_hours ?? null),
                'wages' => Num::money($sum->wages ?? null), 'ot_amount' => Num::money($sum->ot_amount ?? null), 'total_cost' => Num::money($sum->total_cost ?? null)],
            cards: [
                ['key' => 'man_days', 'label' => 'Man-days', 'value' => $manDays, 'type' => 'number'],
                ['key' => 'attendance_cost', 'label' => 'Attendance cost', 'value' => Num::money($sum->total_cost ?? null), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'ledger', 'label' => 'Labour cost (ledger)', 'value' => $ledgerLabour->toMoney(), 'type' => 'money', 'sensitive' => 'financial',
                    'hint' => 'Includes labour expenses and reversals'],
                ['key' => 'payable', 'label' => 'Labour payable (batches)', 'value' => $outstanding->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: ['Only approved attendance counts. Ledger labour cost also includes labour-head expenses, so it can exceed attendance cost.'],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->resources->attendance($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, [
            'subcontractor_id' => $ctx->filter('subcontractor_id'), 'search' => $ctx->filter('search'),
        ]);
    }
}

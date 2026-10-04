<?php

namespace App\Queries\Reports;

use App\Enums\CostHead;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Actual cost — always project_cost_ledger (amounts are signed; reversals are negative rows, so
 * a plain SUM is the net). Every method is company- and project-scoped explicitly because the
 * query builder bypasses the Eloquent tenant scope.
 */
final class CostLedgerQuery
{
    /**
     * @param  list<int>  $projectIds
     */
    public function base(int $companyId, array $projectIds, ?string $from = null, ?string $to = null): Builder
    {
        return DB::table('project_cost_ledger as l')
            ->where('l.company_id', $companyId)
            ->whereIn('l.project_id', $projectIds ?: [0])
            ->when($from, fn ($q) => $q->where('l.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.entry_date', '<=', Sql::eod($to)));
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, Decimal> cost head => net actual (every head present)
     */
    public function byHead(int $companyId, array $projectIds, ?string $from = null, ?string $to = null): array
    {
        $sums = $this->base($companyId, $projectIds, $from, $to)
            ->groupBy('l.cost_head')->selectRaw('l.cost_head, SUM(l.amount) as total')->pluck('total', 'cost_head');

        $out = [];
        foreach (CostHead::cases() as $head) {
            $out[$head->value] = Num::dec($sums[$head->value] ?? null);
        }

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<string, Decimal>> project id => cost head => net actual
     */
    public function byProjectHead(int $companyId, array $projectIds, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        $this->base($companyId, $projectIds, $from, $to)
            ->groupBy('l.project_id', 'l.cost_head')
            ->selectRaw('l.project_id, l.cost_head, SUM(l.amount) as total')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->project_id][$row->cost_head] = Num::dec($row->total);
            });

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     */
    public function total(int $companyId, array $projectIds, ?string $from = null, ?string $to = null): Decimal
    {
        return Num::dec($this->base($companyId, $projectIds, $from, $to)->sum('l.amount'));
    }

    /**
     * Cost per head and source document type (with reversal amounts shown separately).
     *
     * @param  list<int>  $projectIds
     * @return list<object{cost_head: string, source_type: string, entries: int, gross: mixed, reversals: mixed, net: mixed}>
     */
    public function byHeadAndSource(int $companyId, array $projectIds, ?string $from, ?string $to, ?string $head = null): array
    {
        return $this->base($companyId, $projectIds, $from, $to)
            ->when($head, fn ($q) => $q->where('l.cost_head', $head))
            ->groupBy('l.cost_head', 'l.source_type')
            ->selectRaw('l.cost_head, l.source_type, COUNT(*) as entries,
                SUM(CASE WHEN l.is_reversal = 0 THEN l.amount ELSE 0 END) as gross,
                SUM(CASE WHEN l.is_reversal = 1 THEN l.amount ELSE 0 END) as reversals,
                SUM(l.amount) as net')
            ->orderBy('l.cost_head')->orderBy('l.source_type')
            ->get()->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, array<string, Decimal>> YYYY-MM => cost head => net
     */
    public function monthlyByHead(int $companyId, array $projectIds, string $from, string $to): array
    {
        $month = Sql::month('l.entry_date');
        $out = [];
        $this->base($companyId, $projectIds, $from, $to)
            ->groupByRaw("{$month}, l.cost_head")
            ->selectRaw("{$month} as ym, l.cost_head, SUM(l.amount) as total")
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->ym][$row->cost_head] = Num::dec($row->total);
            });
        ksort($out);

        return $out;
    }

    /**
     * Net actual per BOQ line (line_uid survives BOQ revisions).
     *
     * @return array<string, Decimal>
     */
    public function byBoqLine(int $companyId, int $projectId, ?string $to = null): array
    {
        return $this->base($companyId, [$projectId], null, $to)
            ->whereNotNull('l.boq_line_uid')
            ->groupBy('l.boq_line_uid')
            ->selectRaw('l.boq_line_uid, SUM(l.amount) as total')
            ->pluck('total', 'boq_line_uid')
            ->map(fn ($v) => Num::dec($v))
            ->all();
    }

    /**
     * Net subcontract cost posted per work order (through certified bill items).
     *
     * @param  list<int>  $projectIds
     * @return array<int, Decimal> work order id => net posted
     */
    public function subcontractPostedByWorkOrder(int $companyId, array $projectIds, ?string $to = null): array
    {
        return $this->base($companyId, $projectIds, null, $to)
            ->join('subcontractor_bill_items as sbi', function ($join) {
                $join->on('sbi.id', '=', 'l.source_id')->where('l.source_type', '=', 'subcontractor_bill_item');
            })
            ->join('subcontractor_bills as sb', 'sb.id', '=', 'sbi.subcontractor_bill_id')
            ->groupBy('sb.work_order_id')
            ->selectRaw('sb.work_order_id, SUM(l.amount) as total')
            ->pluck('total', 'work_order_id')
            ->map(fn ($v) => Num::dec($v))
            ->all();
    }
}

<?php

namespace App\Queries\Reports;

use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BOQ planned vs actual for a project's current approved BOQ. Lines are matched on line_uid, so
 * executed quantity (progress_entries) and actual cost (project_cost_ledger) recorded against an
 * earlier BOQ revision still land on the same line.
 */
final class BoqProgressQuery
{
    /**
     * @return object{id: int, boq_number: string, version: int, title: string}|null
     */
    public function currentBoq(int $companyId, int $projectId): ?object
    {
        return DB::table('boqs')->where('company_id', $companyId)->where('project_id', $projectId)
            ->where('status', 'approved')->where('is_current', true)->whereNull('deleted_at')
            ->first(['id', 'boq_number', 'version', 'title']);
    }

    public function lines(int $companyId, int $projectId, int $boqId, string $asOf, ?string $search = null): Builder
    {
        $executed = DB::table('progress_entries')->where('company_id', $companyId)->where('project_id', $projectId)
            ->where('entry_date', '<=', Sql::eod($asOf))->whereNotNull('boq_line_uid')
            ->groupBy('boq_line_uid')->selectRaw('boq_line_uid, SUM(quantity) as executed');
        $cost = DB::table('project_cost_ledger')->where('company_id', $companyId)->where('project_id', $projectId)
            ->where('entry_date', '<=', Sql::eod($asOf))->whereNotNull('boq_line_uid')
            ->groupBy('boq_line_uid')->selectRaw('boq_line_uid, SUM(amount) as actual');
        $search = trim((string) $search);

        return DB::table('boq_items as bi')
            ->leftJoin('units as un', 'un.id', '=', 'bi.unit_id')
            ->leftJoinSub($executed, 'e', 'e.boq_line_uid', '=', 'bi.line_uid')
            ->leftJoinSub($cost, 'c', 'c.boq_line_uid', '=', 'bi.line_uid')
            ->where('bi.boq_id', $boqId)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('bi.item_code', 'like', "%{$search}%")->orWhere('bi.name', 'like', "%{$search}%")))
            ->select('bi.id', 'bi.line_uid', 'bi.item_code', 'bi.name', 'un.symbol as unit', 'bi.quantity', 'bi.cost_rate', 'bi.cost_amount', 'bi.client_amount')
            ->selectRaw('COALESCE(e.executed, 0) as executed')
            ->selectRaw('COALESCE(c.actual, 0) as actual');
    }

    /**
     * Totals plus cost / progress not linked to any line of this BOQ.
     *
     * @return array{planned_cost: mixed, client_amount: mixed, actual: mixed, unlinked_cost: mixed, lines: int}
     */
    public function totals(int $companyId, int $projectId, int $boqId, string $asOf): array
    {
        $row = DB::query()->fromSub($this->lines($companyId, $projectId, $boqId, $asOf), 'x')
            ->selectRaw('COUNT(*) as cnt, SUM(cost_amount) as planned_cost, SUM(client_amount) as client_amount, SUM(actual) as actual')->first();
        $uids = DB::table('boq_items')->where('boq_id', $boqId)->select('line_uid');
        $unlinked = DB::table('project_cost_ledger')->where('company_id', $companyId)->where('project_id', $projectId)
            ->where('entry_date', '<=', Sql::eod($asOf))
            ->where(fn ($q) => $q->whereNull('boq_line_uid')->orWhereNotIn('boq_line_uid', $uids))
            ->sum('amount');

        return [
            'lines' => (int) ($row->cnt ?? 0),
            'planned_cost' => Num::money($row->planned_cost ?? null),
            'client_amount' => Num::money($row->client_amount ?? null),
            'actual' => Num::money($row->actual ?? null),
            'unlinked_cost' => Num::money($unlinked),
        ];
    }
}

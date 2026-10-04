<?php

namespace App\Queries\Reports;

use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock from the immutable stock_transactions ledger. value is always positive; the direction
 * comes from qty_in / qty_out (reversal rows swap it), so signed value = in ? value : −value.
 * stock_balances is only a cache: the summary shows it beside the ledger and flags any drift.
 */
final class StockQuery
{
    public const SIGNED_VALUE = 'CASE WHEN st.qty_in > 0 THEN st.value ELSE -st.value END';

    /**
     * Warehouses the scope may see: the projects' stores, plus central stores for company scope.
     *
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    public function warehouseIds(int $companyId, array $projectIds, bool $includeCentral): array
    {
        return DB::table('warehouses')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('project_id', $projectIds ?: [0])->when($includeCentral, fn ($c) => $c->orWhereNull('project_id')))
            ->orderBy('code')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Per warehouse × material: ledger quantity / value as of a date, the cache, last movements.
     *
     * @param  list<int>  $warehouseIds
     * @param  array{material_id?: int|null, category_id?: int|null, search?: string|null, status?: string|null}  $filters
     */
    public function summary(int $companyId, array $warehouseIds, string $asOf, array $filters = [], bool $compareCache = true): Builder
    {
        $ids = $warehouseIds ?: [0];
        $eod = Sql::eod($asOf);
        $ledger = DB::table('stock_transactions as st')
            ->where('st.company_id', $companyId)->whereIn('st.warehouse_id', $ids)->where('st.txn_date', '<=', $eod)
            ->groupBy('st.warehouse_id', 'st.material_id')
            ->selectRaw('st.warehouse_id, st.material_id, SUM(st.qty_in) - SUM(st.qty_out) as qty, SUM('.self::SIGNED_VALUE.') as value,
                MAX(st.txn_date) as last_txn, MAX(CASE WHEN st.qty_out > 0 AND st.txn_type <> \'reversal\' THEN st.txn_date END) as last_out');

        $keys = DB::table('stock_transactions')->where('company_id', $companyId)->whereIn('warehouse_id', $ids)->where('txn_date', '<=', $eod)
            ->select('warehouse_id', 'material_id')
            ->union(DB::table('stock_balances')->where('company_id', $companyId)->whereIn('warehouse_id', $ids)->select('warehouse_id', 'material_id'));

        $search = trim((string) ($filters['search'] ?? ''));
        $slowBefore = date('Y-m-d', strtotime($asOf.' -90 days'));
        $query = DB::query()->fromSub($keys, 'k')
            ->join('materials as mt', 'mt.id', '=', 'k.material_id')
            ->join('warehouses as w', 'w.id', '=', 'k.warehouse_id')
            ->leftJoin('projects as pr', 'pr.id', '=', 'w.project_id')
            ->leftJoin('units as un', 'un.id', '=', 'mt.unit_id')
            ->leftJoin('material_categories as mc', 'mc.id', '=', 'mt.material_category_id')
            ->leftJoinSub($ledger, 'lg', fn ($j) => $j->on('lg.warehouse_id', '=', 'k.warehouse_id')->on('lg.material_id', '=', 'k.material_id'))
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.warehouse_id', '=', 'k.warehouse_id')->on('sb.material_id', '=', 'k.material_id'))
            ->when($filters['material_id'] ?? null, fn ($q, $id) => $q->where('k.material_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('mt.material_category_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('mt.name', 'like', "%{$search}%")->orWhere('mt.code', 'like', "%{$search}%")))
            ->select('k.warehouse_id', 'k.material_id', 'w.code as warehouse_code', 'w.name as warehouse', 'pr.code as project_code', 'w.project_id',
                'mt.code as material_code', 'mt.name as material', 'un.symbol as unit', 'mc.name as category', 'mt.reorder_level',
                'lg.last_txn', 'lg.last_out')
            ->selectRaw('COALESCE(lg.qty, 0) as qty, COALESCE(lg.value, 0) as value')
            ->selectRaw('COALESCE(sb.quantity, 0) as cache_qty, COALESCE(sb.value, 0) as cache_value, sb.avg_cost as cache_avg_cost');

        $drift = 'ABS(COALESCE(lg.qty, 0) - COALESCE(sb.quantity, 0)) > 0.00005 OR ABS(COALESCE(lg.value, 0) - COALESCE(sb.value, 0)) > 0.005';

        return match ($filters['status'] ?? null) {
            'low' => $query->where('mt.reorder_level', '>', 0)->whereRaw('COALESCE(lg.qty, 0) <= mt.reorder_level'),
            'slow' => $query->whereRaw('COALESCE(lg.qty, 0) > 0')->where(fn ($q) => $q->whereNull('lg.last_out')->orWhere('lg.last_out', '<', $slowBefore)),
            'drift' => $compareCache ? $query->whereRaw("({$drift})") : $query->whereRaw('1 = 0'),
            default => $query->where(fn ($q) => $q->whereRaw('COALESCE(lg.qty, 0) <> 0')->orWhereRaw('COALESCE(sb.quantity, 0) <> 0')),
        };
    }

    /**
     * Ledger rows in a period with running quantity / value per warehouse × material (the window
     * runs over the full history so the first row of the period already includes the opening).
     *
     * @param  list<int>  $warehouseIds
     * @param  array{material_id?: int|null, txn_type?: string|null, search?: string|null}  $filters
     */
    public function ledger(int $companyId, array $warehouseIds, string $from, string $to, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $inner = DB::table('stock_transactions as st')
            ->where('st.company_id', $companyId)->whereIn('st.warehouse_id', $warehouseIds ?: [0])->where('st.txn_date', '<=', Sql::eod($to))
            ->when($filters['material_id'] ?? null, fn ($q, $id) => $q->where('st.material_id', $id))
            ->select('st.id', 'st.txn_date', 'st.txn_type', 'st.warehouse_id', 'st.material_id', 'st.project_id', 'st.qty_in', 'st.qty_out',
                'st.unit_cost', 'st.source_type', 'st.source_id', 'st.remarks')
            ->selectRaw(self::SIGNED_VALUE.' as signed_value')
            ->selectRaw('SUM(st.qty_in - st.qty_out) OVER (PARTITION BY st.warehouse_id, st.material_id ORDER BY st.txn_date, st.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_qty')
            ->selectRaw('SUM('.self::SIGNED_VALUE.') OVER (PARTITION BY st.warehouse_id, st.material_id ORDER BY st.txn_date, st.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_value');

        return DB::query()->fromSub($inner, 'x')
            ->join('materials as mt', 'mt.id', '=', 'x.material_id')
            ->join('warehouses as w', 'w.id', '=', 'x.warehouse_id')
            ->leftJoin('projects as pr', 'pr.id', '=', 'x.project_id')
            ->leftJoin('units as un', 'un.id', '=', 'mt.unit_id')
            ->where('x.txn_date', '>=', $from)
            ->when($filters['txn_type'] ?? null, fn ($q, $t) => $q->where('x.txn_type', $t))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('mt.name', 'like', "%{$search}%")->orWhere('mt.code', 'like', "%{$search}%")))
            ->select('x.*', 'mt.code as material_code', 'mt.name as material', 'un.symbol as unit', 'w.code as warehouse_code', 'pr.code as project_code');
    }

    /**
     * Document numbers / links for ledger rows, resolved in one query per source type.
     *
     * @param  iterable<object>  $rows  with source_type, source_id
     * @return array<string, array{number: string|null, project_id: int|null, route: string|null, id: int|null}> "type:id" => ref
     */
    public function documents(iterable $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->source_type][] = (int) $row->source_id;
        }

        $maps = [
            'grn_item' => ['grn_items', 'grn_id', 'grns', 'grn_number', 'projects.grns.show'],
            'material_issue_item' => ['material_issue_items', 'material_issue_id', 'material_issues', 'issue_number', 'projects.material-issues.show'],
            'material_return_item' => ['material_return_items', 'material_return_id', 'material_returns', 'return_number', 'projects.material-returns.show'],
            'stock_adjustment_item' => ['stock_adjustment_items', 'stock_adjustment_id', 'stock_adjustments', 'adjustment_number', 'projects.stock-adjustments.show'],
            'stock_transfer_item' => ['stock_transfer_items', 'stock_transfer_id', 'stock_transfers', 'transfer_number', 'projects.stock-transfers.show'],
        ];

        $out = [];
        foreach ($byType as $type => $ids) {
            if ($type === 'stock_transfer_receipt_item') {
                DB::table('stock_transfer_receipt_items as ri')
                    ->join('stock_transfer_receipts as r', 'r.id', '=', 'ri.stock_transfer_receipt_id')
                    ->join('stock_transfers as d', 'd.id', '=', 'r.stock_transfer_id')
                    ->whereIn('ri.id', array_unique($ids))
                    ->get(['ri.id', 'd.id as doc_id', 'd.transfer_number as number', 'd.project_id'])
                    ->each(function ($r) use (&$out, $type) {
                        $out["{$type}:{$r->id}"] = ['number' => $r->number, 'project_id' => $r->project_id ? (int) $r->project_id : null, 'route' => 'projects.stock-transfers.show', 'id' => (int) $r->doc_id];
                    });

                continue;
            }
            if (! isset($maps[$type])) {
                continue;
            }
            [$itemTable, $fk, $docTable, $numberColumn, $route] = $maps[$type];
            DB::table("{$itemTable} as it")->join("{$docTable} as d", 'd.id', '=', "it.{$fk}")
                ->whereIn('it.id', array_unique($ids))
                ->get(['it.id', 'd.id as doc_id', "d.{$numberColumn} as number", 'd.project_id'])
                ->each(function ($r) use (&$out, $type, $route) {
                    $out["{$type}:{$r->id}"] = ['number' => $r->number, 'project_id' => $r->project_id ? (int) $r->project_id : null, 'route' => $route, 'id' => (int) $r->doc_id];
                });
        }

        return $out;
    }
}

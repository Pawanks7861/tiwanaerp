<?php

namespace App\Services\Inventory;

use App\Enums\Procurement\GrnStatus;
use App\Models\Procurement\Grn;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes balances from the append-only ledger and compares them with the stock_balances
 * cache (architecture H.9). The ledger is never modified; fix() rewrites only cache rows.
 * Must run inside an explicit company context (CurrentCompany::runAs).
 */
class InventoryReconciler
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly StockLedgerService $ledger,
        private readonly GrnStockPoster $grnPoster,
    ) {}

    /**
     * Warehouse + material pairs whose cached balance differs from the ledger.
     *
     * @return list<array{warehouse_id: int, material_id: int, ledger_qty: string, ledger_value: string, ledger_avg: string, cached_qty: string|null, cached_value: string|null, cached_avg: string|null}>
     */
    public function drift(?int $warehouseId = null, ?int $materialId = null): array
    {
        $companyId = $this->tenancy->require()->id;
        $filter = fn ($query) => $query->where('company_id', $companyId)
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($materialId, fn ($q, $id) => $q->where('material_id', $id));

        $ledger = $filter(DB::table('stock_transactions'))
            ->groupBy('warehouse_id', 'material_id')
            ->selectRaw('warehouse_id, material_id, SUM(qty_in) - SUM(qty_out) as qty, SUM(CASE WHEN qty_in > 0 THEN value ELSE -value END) as value')
            ->get()
            ->keyBy(fn ($row) => "{$row->warehouse_id}:{$row->material_id}");

        $cache = $filter(DB::table('stock_balances'))
            ->get(['warehouse_id', 'material_id', 'quantity', 'avg_cost', 'value'])
            ->keyBy(fn ($row) => "{$row->warehouse_id}:{$row->material_id}");

        $drift = [];
        foreach ($ledger->keys()->merge($cache->keys())->unique()->sort()->values() as $key) {
            $l = $ledger->get($key);
            $c = $cache->get($key);
            [$warehouse, $material] = array_map('intval', explode(':', $key));

            $qty = $this->number($l?->qty, Decimal::QTY_SCALE);
            $value = $this->number($l?->value, Decimal::MONEY_SCALE);
            $avg = $qty->isZero() ? Decimal::zero() : $value->dividedBy($qty)->round(Decimal::RATE_SCALE);

            $cachedQty = $c ? $this->number($c->quantity, Decimal::QTY_SCALE) : null;
            $cachedValue = $c ? $this->number($c->value, Decimal::MONEY_SCALE) : null;
            $cachedAvg = $c ? $this->number($c->avg_cost, Decimal::RATE_SCALE) : null;

            $matches = $c === null
                ? $qty->isZero() && $value->isZero()
                : $qty->equals($cachedQty) && $value->equals($cachedValue) && $avg->equals($cachedAvg);

            if (! $matches) {
                $drift[] = [
                    'warehouse_id' => $warehouse,
                    'material_id' => $material,
                    'ledger_qty' => $qty->toQuantity(),
                    'ledger_value' => $value->toMoney(),
                    'ledger_avg' => $avg->toRate(),
                    'cached_qty' => $cachedQty?->toQuantity(),
                    'cached_value' => $cachedValue?->toMoney(),
                    'cached_avg' => $cachedAvg?->toRate(),
                ];
            }
        }

        return $drift;
    }

    /**
     * Rewrite the cache rows of the given drift entries from the ledger (re-read under lock).
     *
     * @param  list<array{warehouse_id: int, material_id: int}>  $drift
     */
    public function fix(array $drift): int
    {
        $fixed = 0;

        foreach ($drift as $row) {
            DB::transaction(function () use ($row, &$fixed) {
                $balance = $this->ledger->lockBalance($row['warehouse_id'], $row['material_id']);
                $fresh = $this->drift($row['warehouse_id'], $row['material_id'])[0] ?? null;
                if ($fresh === null) {
                    return;
                }

                $balance->forceFill([
                    'quantity' => $fresh['ledger_qty'],
                    'value' => $fresh['ledger_value'],
                    'avg_cost' => $fresh['ledger_avg'],
                    'updated_at' => now(),
                ])->save();
                $fixed++;
            });
        }

        return $fixed;
    }

    /**
     * Approved GRNs with accepted lines that have no grn_in posting yet.
     *
     * @return list<array{grn: Grn, items: int}>
     */
    public function unpostedGrns(): array
    {
        return Grn::query()->where('status', GrnStatus::Approved)->orderBy('id')->get()
            ->map(fn (Grn $grn) => ['grn' => $grn, 'items' => count($this->grnPoster->unpostedItems($grn))])
            ->filter(fn (array $row) => $row['items'] > 0)
            ->values()
            ->all();
    }

    /**
     * Ledger / cache values arrive as exact strings on MySQL and possibly floats on SQLite.
     */
    private function number(mixed $value, int $scale): Decimal
    {
        if ($value === null) {
            return Decimal::zero()->round($scale);
        }

        return Decimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) $value)->round($scale);
    }
}

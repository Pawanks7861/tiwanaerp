<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Enums\Procurement\GrnStatus;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Posts the accepted quantities of an approved GRN as grn_in movements. Unit cost is the PO line
 * rate net of its line discount (GST is input credit, freight / other charges are not allocated
 * in Phase 4). Idempotent: lines already posted are returned untouched.
 */
class GrnStockPoster
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    /**
     * @param  Warehouse|null  $fallback  receiving warehouse for legacy GRNs approved without one
     * @return list<array{item: GrnItem, transaction: StockTransaction, created: bool}>
     */
    public function post(Grn $grn, ?Warehouse $fallback = null, ?string $remarks = null, ?int $userId = null): array
    {
        if ($grn->status !== GrnStatus::Approved) {
            throw new LogicException("GRN {$grn->grn_number} is not approved.");
        }

        $warehouse = $grn->warehouse_id !== null
            ? Warehouse::query()->withTrashed()->findOrFail($grn->warehouse_id)
            : ($fallback ?? throw new LogicException("GRN {$grn->grn_number} has no warehouse."));

        return DB::transaction(function () use ($grn, $warehouse, $remarks, $userId) {
            $results = [];

            foreach ($this->postableItems($grn) as $item) {
                $transaction = $this->ledger->post(new StockMovement(
                    source: $item,
                    type: StockTxnType::GrnIn,
                    warehouse: $warehouse,
                    materialId: $item->material_id,
                    quantity: Decimal::of($item->accepted_qty),
                    date: $grn->receipt_date->toDateString(),
                    projectId: $grn->project_id,
                    unitCost: $this->unitCost($item),
                    remarks: $remarks ?? $grn->grn_number,
                    userId: $userId ?? $grn->approved_by,
                ));

                $results[] = ['item' => $item, 'transaction' => $transaction, 'created' => $transaction->wasRecentlyCreated];
            }

            return $results;
        });
    }

    /**
     * Lines with an accepted quantity that have no grn_in posting yet.
     *
     * @return list<GrnItem>
     */
    public function unpostedItems(Grn $grn): array
    {
        $posted = StockTransaction::query()
            ->where('source_type', (new GrnItem)->getMorphClass())
            ->whereIn('source_id', $grn->items()->pluck('id'))
            ->where('txn_type', StockTxnType::GrnIn)
            ->pluck('source_id')
            ->all();

        return array_values(array_filter($this->postableItems($grn), fn (GrnItem $item) => ! in_array($item->id, $posted, false)));
    }

    public function unitCost(GrnItem $item): Decimal
    {
        $discount = PurchaseOrderItem::query()->whereKey($item->purchase_order_item_id)->value('discount_percent') ?? '0';
        $rate = Decimal::of($item->rate);

        return $rate->minus($rate->percentOf($discount))->round(Decimal::RATE_SCALE);
    }

    /**
     * @return list<GrnItem>
     */
    private function postableItems(Grn $grn): array
    {
        return $grn->items()->reorder('material_id')->orderBy('id')->get()
            ->filter(fn (GrnItem $item) => Decimal::of($item->accepted_qty)->isPositive())
            ->values()
            ->all();
    }
}

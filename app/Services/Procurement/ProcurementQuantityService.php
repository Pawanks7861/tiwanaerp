<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\RfqStatus;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\RfqItem;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of the procurement quantity rules. Every cache (MR ordered/received, PO received)
 * and every derived status is recomputed from the source documents, never incremented, so repeating
 * a recompute is harmless (idempotent).
 *
 * Ordered quantity of an MR line counts purchase order lines that are approved (incl. partially
 * received / received) or under amendment (an approved order that was reopened); closed and cancelled
 * orders count only what was received. Received quantity is the accepted quantity of approved GRNs.
 */
class ProcurementQuantityService
{
    /**
     * Quantity of each MR line already reserved by open RFQs and by purchase orders of any
     * non-terminated status (drafts included), used to prevent over-procurement.
     *
     * @param  list<int>  $itemIds
     * @return array<int, Decimal>
     */
    public function allocated(array $itemIds, ?int $exceptRfqId = null, ?int $exceptOrderId = null): array
    {
        $result = array_fill_keys($itemIds, Decimal::zero());
        if ($itemIds === []) {
            return $result;
        }

        $openRfq = array_map(fn (RfqStatus $s) => $s->value, array_filter(RfqStatus::cases(), fn (RfqStatus $s) => $s->isOpen()));
        RfqItem::query()
            ->whereIn('material_request_item_id', $itemIds)
            ->whereHas('rfq', fn (Builder $q) => $q->whereIn('status', $openRfq))
            ->when($exceptRfqId, fn (Builder $q, int $id) => $q->where('rfq_id', '!=', $id))
            ->get(['material_request_item_id', 'quantity'])
            ->each(function (RfqItem $line) use (&$result) {
                $result[$line->material_request_item_id] = $result[$line->material_request_item_id]->plus($line->quantity);
            });

        PurchaseOrderItem::query()
            ->whereIn('material_request_item_id', $itemIds)
            ->whereHas('purchaseOrder')
            ->when($exceptOrderId, fn (Builder $q, int $id) => $q->where('purchase_order_id', '!=', $id))
            ->with('purchaseOrder:id,status')
            ->get(['id', 'purchase_order_id', 'material_request_item_id', 'quantity', 'received_qty'])
            ->each(function (PurchaseOrderItem $line) use (&$result) {
                $qty = $line->purchaseOrder->status->isTerminated() ? $line->received_qty : $line->quantity;
                $result[$line->material_request_item_id] = $result[$line->material_request_item_id]->plus($qty);
            });

        return $result;
    }

    /**
     * Remaining procurable quantity per MR line (never negative).
     *
     * @param  iterable<MaterialRequestItem>  $items
     * @return array<int, string>
     */
    public function remaining(iterable $items, ?int $exceptRfqId = null, ?int $exceptOrderId = null): array
    {
        $items = collect($items);
        $allocated = $this->allocated($items->pluck('id')->all(), $exceptRfqId, $exceptOrderId);

        return $items->mapWithKeys(function (MaterialRequestItem $item) use ($allocated) {
            $left = Decimal::of($item->quantity)->minus($allocated[$item->id]);

            return [$item->id => ($left->isNegative() ? Decimal::zero() : $left)->toQuantity()];
        })->all();
    }

    /**
     * Recompute ordered_qty / received_qty of the given MR lines and the derived status of their requests.
     *
     * @param  list<int>  $itemIds
     */
    public function refreshMaterialRequests(array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter($itemIds)));
        if ($itemIds === []) {
            return;
        }

        DB::transaction(function () use ($itemIds) {
            $items = MaterialRequestItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get();
            $ordered = array_fill_keys($itemIds, Decimal::zero());
            $received = array_fill_keys($itemIds, Decimal::zero());

            PurchaseOrderItem::query()
                ->whereIn('material_request_item_id', $itemIds)
                ->whereHas('purchaseOrder')
                ->with('purchaseOrder:id,status,revision_no')
                ->get(['id', 'purchase_order_id', 'material_request_item_id', 'quantity', 'received_qty'])
                ->each(function (PurchaseOrderItem $line) use (&$ordered, &$received) {
                    $id = $line->material_request_item_id;
                    $ordered[$id] = $ordered[$id]->plus($this->orderedContribution($line->purchaseOrder, $line));
                    $received[$id] = $received[$id]->plus($line->received_qty);
                });

            foreach ($items as $item) {
                $item->forceFill([
                    'ordered_qty' => $ordered[$item->id]->toQuantity(),
                    'received_qty' => $received[$item->id]->toQuantity(),
                ]);
                if ($item->isDirty()) {
                    $item->save();
                }
            }

            MaterialRequest::query()->whereIn('id', $items->pluck('material_request_id')->unique())->lockForUpdate()->get()
                ->each(fn (MaterialRequest $mr) => $this->deriveRequestStatus($mr));
        });
    }

    /**
     * Recompute received_qty of every PO line from approved GRNs, the PO receipt status, and the
     * linked MR lines.
     */
    public function refreshPurchaseOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->lockForUpdate()->get();
            $received = array_fill_keys($items->pluck('id')->all(), Decimal::zero());

            GrnItem::query()
                ->whereIn('purchase_order_item_id', $items->pluck('id'))
                ->whereHas('grn', fn (Builder $q) => $q->where('status', GrnStatus::Approved->value))
                ->get(['purchase_order_item_id', 'accepted_qty'])
                ->each(function (GrnItem $line) use (&$received) {
                    $received[$line->purchase_order_item_id] = $received[$line->purchase_order_item_id]->plus($line->accepted_qty);
                });

            foreach ($items as $item) {
                $item->forceFill(['received_qty' => $received[$item->id]->toQuantity()]);
                if ($item->isDirty()) {
                    $item->save();
                }
            }

            if ($order->status->isApprovedOrder()) {
                $complete = $items->every(fn (PurchaseOrderItem $i) => Decimal::of($i->received_qty)->greaterThanOrEqual($i->quantity));
                $any = $items->contains(fn (PurchaseOrderItem $i) => Decimal::of($i->received_qty)->isPositive());
                $status = $complete ? PurchaseOrderStatus::Received : ($any ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Approved);
                if ($status !== $order->status) {
                    $order->forceFill(['status' => $status])->save();
                }
            }

            $this->refreshMaterialRequests($items->pluck('material_request_item_id')->filter()->all());
        });
    }

    private function orderedContribution(PurchaseOrder $order, PurchaseOrderItem $line): string
    {
        $status = $order->status;

        if ($status->isTerminated()) {
            return (string) $line->received_qty;
        }

        $underAmendment = $order->revision_no > 0 && in_array($status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Rejected], true);

        return $status->isApprovedOrder() || $underAmendment ? (string) $line->quantity : '0';
    }

    private function deriveRequestStatus(MaterialRequest $mr): void
    {
        if (! $mr->status->isProcurable()) {
            return;
        }

        $items = $mr->items()->get(['id', 'material_request_id', 'quantity', 'ordered_qty', 'received_qty']);
        $allReceived = $items->every(fn (MaterialRequestItem $i) => Decimal::of($i->received_qty)->greaterThanOrEqual($i->quantity));
        $allOrdered = $items->every(fn (MaterialRequestItem $i) => Decimal::of($i->ordered_qty)->greaterThanOrEqual($i->quantity));
        $anyOrdered = $items->contains(fn (MaterialRequestItem $i) => Decimal::of($i->ordered_qty)->isPositive() || Decimal::of($i->received_qty)->isPositive());

        $status = match (true) {
            $items->isNotEmpty() && $allReceived => MaterialRequestStatus::Received,
            $items->isNotEmpty() && $allOrdered => MaterialRequestStatus::Ordered,
            $anyOrdered => MaterialRequestStatus::PartiallyOrdered,
            default => MaterialRequestStatus::Approved,
        };

        if ($status !== $mr->status) {
            $mr->forceFill(['status' => $status])->save();
        }
    }
}

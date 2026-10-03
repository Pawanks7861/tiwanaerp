<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\GrnStatus;
use App\Events\Procurement\GrnApproved;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use App\Support\Procurement\ProcurementSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods receipts. accepted = received − rejected is computed here; accepted quantities across
 * approved and pending GRNs may not exceed the ordered quantity plus the over-receipt tolerance.
 * Approval raises GrnApproved, on which the inventory module posts the accepted quantities into
 * the GRN's warehouse, so a warehouse is required from submission on.
 */
class GrnService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProcurementQuantityService $quantities,
        private readonly ProcurementSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(PurchaseOrder $po, array $data): Grn
    {
        return DB::transaction(function () use ($po, $data) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $this->assertReceivable($po);

            $grn = new Grn($this->header($data));
            $grn->forceFill([
                'project_id' => $po->project_id,
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'grn_number' => $this->numbers->next('grn', Project::query()->findOrFail($po->project_id)),
                'status' => GrnStatus::Draft,
            ])->save();

            $this->replaceLines($grn, $po, $data['items']);

            return $grn;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Grn $grn, array $data): Grn
    {
        return DB::transaction(function () use ($grn, $data) {
            $grn = Grn::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $grn->assertEditable();
            $po = PurchaseOrder::query()->whereKey($grn->purchase_order_id)->lockForUpdate()->firstOrFail();
            $this->assertReceivable($po);

            $grn->fill($this->header($data))->save();
            $this->replaceLines($grn, $po, $data['items']);

            return $grn;
        });
    }

    public function delete(Grn $grn): void
    {
        $grn->assertEditable();
        $grn->delete();
    }

    public function submit(Grn $grn, User $user): void
    {
        DB::transaction(function () use ($grn, $user) {
            $locked = Grn::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $po = PurchaseOrder::query()->whereKey($locked->purchase_order_id)->lockForUpdate()->firstOrFail();
            $this->assertReceivable($po);

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['grn' => 'Add at least one received line before submitting.']);
            }
            $this->assertHasWarehouse($locked);
            $this->assertWithinTolerance($po, $locked, $items->all(), includePending: true);

            $this->approvals->submit($locked, $user);
            $grn->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the approval engine's transaction). The GRN becomes immutable and the
     * PO / MR received quantities are recomputed from all approved GRNs. Repeating it changes nothing.
     */
    public function markApproved(Grn $grn, ?int $approverId): void
    {
        DB::transaction(function () use ($grn, $approverId) {
            $grn = Grn::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            if ($grn->status === GrnStatus::Approved) {
                return;
            }

            $po = PurchaseOrder::query()->whereKey($grn->purchase_order_id)->lockForUpdate()->firstOrFail();
            if (! $po->status->isApprovedOrder()) {
                throw ValidationException::withMessages(['purchase_order' => 'The purchase order is no longer approved, so this receipt cannot be approved.']);
            }
            $this->assertWithinTolerance($po, $grn, $grn->items()->get()->all(), includePending: false);
            $this->assertHasWarehouse($grn);

            $grn->forceFill(['status' => GrnStatus::Approved, 'approved_by' => $approverId, 'approved_at' => now()])->save();

            $this->quantities->refreshPurchaseOrder($po);
            GrnApproved::dispatch($grn->fresh());
        });
    }

    /**
     * Receivable PO lines with what is still open, for pre-filling the GRN form.
     *
     * @return list<array<string, mixed>>
     */
    public function receivableLines(PurchaseOrder $po, ?Grn $except = null): array
    {
        $tolerance = $this->settings->grnTolerancePercent(Project::query()->findOrFail($po->project_id));
        $pending = $this->acceptedElsewhere($po, $except?->id, includePending: true);

        return $po->items()->with(['unit:id,symbol', 'material:id,code,name'])->get()->map(function (PurchaseOrderItem $item) use ($tolerance, $pending) {
            $max = Decimal::of($item->quantity)->plus(Decimal::of($item->quantity)->percentOf($tolerance))->round(Decimal::QTY_SCALE);
            $open = $max->minus($pending[$item->id] ?? '0');

            return [
                'purchase_order_item_id' => $item->id,
                'material_id' => $item->material_id,
                'item_code' => $item->item_code,
                'description' => $item->description,
                'unit' => $item->unit?->symbol,
                'ordered_qty' => $item->quantity,
                'received_qty' => $item->received_qty,
                'pending_qty' => Decimal::of($pending[$item->id] ?? '0')->minus($item->received_qty)->toQuantity(),
                'remaining_qty' => Decimal::of($item->quantity)->minus($pending[$item->id] ?? '0')->toQuantity(),
                'max_acceptable_qty' => ($open->isNegative() ? Decimal::zero() : $open)->toQuantity(),
                'rate' => $item->rate,
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows  purchase_order_item_id, received_qty, rejected_qty, rejection_reason
     */
    private function replaceLines(Grn $grn, PurchaseOrder $po, array $rows): void
    {
        $poItems = $po->items()->get()->keyBy('id');
        GrnItem::query()->where('grn_id', $grn->id)->get()->each->delete();

        $lines = [];
        $seen = [];
        foreach (array_values($rows) as $index => $row) {
            $poItem = $poItems->get((int) $row['purchase_order_item_id'])
                ?? throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => 'This line is not part of the purchase order.']);
            if (isset($seen[$poItem->id])) {
                throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => 'Each purchase order line can only appear once.']);
            }
            $seen[$poItem->id] = true;

            $received = Decimal::of((string) ($row['received_qty'] ?? '0'));
            $rejected = Decimal::of((string) ($row['rejected_qty'] ?? '0'));
            if ($received->isZero() && $rejected->isZero()) {
                continue;
            }
            if ($received->isNegative() || $rejected->isNegative() || $rejected->greaterThan($received)) {
                throw ValidationException::withMessages(["items.{$index}.rejected_qty" => 'The rejected quantity cannot exceed the received quantity.']);
            }
            if ($rejected->isPositive() && blank($row['rejection_reason'] ?? null)) {
                throw ValidationException::withMessages(["items.{$index}.rejection_reason" => 'Give the reason for the rejected quantity.']);
            }

            $line = (new GrnItem)->forceFill([
                'grn_id' => $grn->id,
                'purchase_order_item_id' => $poItem->id,
                'material_id' => $poItem->material_id,
                'unit_id' => $poItem->unit_id,
                'ordered_qty' => $poItem->quantity,
                'previously_received_qty' => $poItem->received_qty,
                'received_qty' => $received->toQuantity(),
                'rejected_qty' => $rejected->toQuantity(),
                'accepted_qty' => $received->minus($rejected)->toQuantity(),
                'rejection_reason' => $rejected->isPositive() ? $row['rejection_reason'] : null,
                'rate' => $poItem->rate,
            ]);
            $line->save();
            $lines[$index] = $line;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Enter the received quantity for at least one line.']);
        }

        $this->assertWithinTolerance($po, $grn, $lines, includePending: true);
    }

    /**
     * @param  array<int, GrnItem>  $lines  keyed by form row index
     */
    private function assertWithinTolerance(PurchaseOrder $po, Grn $grn, array $lines, bool $includePending): void
    {
        $tolerance = $this->settings->grnTolerancePercent(Project::query()->findOrFail($po->project_id));
        $elsewhere = $this->acceptedElsewhere($po, $grn->id, $includePending);
        $poItems = $po->items()->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            $poItem = $poItems->get($line->purchase_order_item_id);
            $max = Decimal::of($poItem->quantity)->plus(Decimal::of($poItem->quantity)->percentOf($tolerance));
            $total = Decimal::of($elsewhere[$poItem->id] ?? '0')->plus($line->accepted_qty);

            if ($total->greaterThan($max)) {
                $allowed = $max->minus($elsewhere[$poItem->id] ?? '0');
                throw ValidationException::withMessages([
                    "items.{$index}.received_qty" => sprintf(
                        '%s: over-receipt. At most %s more can be accepted (ordered %s, tolerance %s%%).',
                        $poItem->description,
                        ($allowed->isNegative() ? Decimal::zero() : $allowed)->toQuantity(),
                        $poItem->quantity,
                        rtrim(rtrim($tolerance, '0'), '.'),
                    ),
                ]);
            }
        }
    }

    /**
     * Accepted quantity per PO line on approved GRNs (and, optionally, submitted ones) other than $exceptGrnId.
     *
     * @return array<int, string>
     */
    private function acceptedElsewhere(PurchaseOrder $po, ?int $exceptGrnId, bool $includePending): array
    {
        $statuses = $includePending ? [GrnStatus::Approved->value, GrnStatus::Submitted->value] : [GrnStatus::Approved->value];
        $totals = [];

        GrnItem::query()
            ->whereIn('purchase_order_item_id', $po->items()->pluck('id'))
            ->whereHas('grn', fn (Builder $q) => $q->whereIn('status', $statuses)->when($exceptGrnId, fn (Builder $w, int $id) => $w->whereKeyNot($id)))
            ->get(['purchase_order_item_id', 'accepted_qty'])
            ->each(function (GrnItem $line) use (&$totals) {
                $totals[$line->purchase_order_item_id] = Decimal::of($totals[$line->purchase_order_item_id] ?? '0')->plus($line->accepted_qty)->toQuantity();
            });

        return $totals;
    }

    private function assertHasWarehouse(Grn $grn): void
    {
        if ($grn->warehouse_id === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose the receiving warehouse; accepted stock is posted there on approval.']);
        }
    }

    private function assertReceivable(PurchaseOrder $po): void
    {
        if (! $po->status->isReceivable()) {
            throw ValidationException::withMessages(['purchase_order' => 'Goods can only be received against an approved purchase order that is not fully received.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'receipt_date' => $data['receipt_date'],
            'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
            'vendor_invoice_date' => $data['vendor_invoice_date'] ?? null,
            'vendor_challan_no' => $data['vendor_challan_no'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}

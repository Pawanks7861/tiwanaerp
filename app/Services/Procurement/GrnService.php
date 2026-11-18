<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\GrnExtraReason;
use App\Enums\Procurement\GrnStatus;
use App\Events\Procurement\GrnApproved;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Goods receipts. accepted = received − rejected is computed here. A delivery may exceed the
 * open PO quantity when a reason is recorded and the receipt is confirmed; the full accepted
 * quantity is posted to stock. Approval raises GrnApproved. A warehouse is required from submission on.
 */
class GrnService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProcurementQuantityService $quantities,
        private readonly WeighbridgeReceiptService $weighbridge,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(PurchaseOrder $po, array $data): Grn
    {
        $uuid = $data['uuid'] ?? null;
        if ($uuid !== null && ($existing = $this->byUuid($po, $uuid))) {
            return $existing;
        }

        return DB::transaction(function () use ($po, $data, $uuid) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $this->assertReceivable($po);

            $grn = new Grn($this->header($data));
            $grn->forceFill([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'project_id' => $po->project_id,
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'grn_number' => $this->numbers->next('grn', Project::query()->findOrFail($po->project_id)),
                'status' => GrnStatus::Draft,
            ])->save();

            $this->replaceLines($grn, $po, $data['items'], (bool) ($data['confirm_extra'] ?? false));
            $this->weighbridge->sync($grn, $data['weighbridge'] ?? null);

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
            $this->replaceLines($grn, $po, $data['items'], (bool) ($data['confirm_extra'] ?? false));
            $this->weighbridge->sync($grn, $data['weighbridge'] ?? null);

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
        $pending = $this->acceptedElsewhere($po, $except?->id, includePending: true);

        return $po->items()->with(['unit:id,symbol', 'material:id,code,name'])->get()->map(function (PurchaseOrderItem $item) use ($pending) {
            $open = Decimal::of($item->quantity)->minus($pending[$item->id] ?? '0');

            return [
                'purchase_order_item_id' => $item->id,
                'material_id' => $item->material_id,
                'item_code' => $item->item_code,
                'description' => $item->description,
                'unit' => $item->unit?->symbol,
                'ordered_qty' => $item->quantity,
                'received_qty' => $item->received_qty,
                'pending_qty' => Decimal::of($pending[$item->id] ?? '0')->minus($item->received_qty)->toQuantity(),
                'remaining_qty' => ($open->isNegative() ? Decimal::zero() : $open)->toQuantity(),
                'rate' => $item->rate,
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(Grn $grn, PurchaseOrder $po, array $rows, bool $confirmed): void
    {
        $poItems = $po->items()->get()->keyBy('id');
        $elsewhere = $this->acceptedElsewhere($po, $grn->id, includePending: true);
        GrnItem::query()->where('grn_id', $grn->id)->get()->each->delete();

        $lines = [];
        $seen = [];
        $extras = [];
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

            $open = Decimal::of($poItem->quantity)->minus($elsewhere[$poItem->id] ?? '0');
            $remaining = $open->isNegative() ? Decimal::zero() : $open;
            $extra = $received->minus($remaining);
            $extra = $extra->isNegative() ? Decimal::zero() : $extra;
            $reason = GrnExtraReason::tryFrom((string) ($row['extra_reason'] ?? ''));
            if ($extra->isPositive() && $reason === null) {
                throw ValidationException::withMessages(["items.{$index}.extra_reason" => 'Give the reason for the quantity above the purchase order.']);
            }
            if ($reason === GrnExtraReason::Other && blank($row['extra_remarks'] ?? null)) {
                throw ValidationException::withMessages(["items.{$index}.extra_remarks" => 'Describe the other reason for the extra quantity.']);
            }

            $challan = blank($row['supplier_challan_qty'] ?? null) ? null : Decimal::of((string) $row['supplier_challan_qty'])->toQuantity();
            $line = (new GrnItem)->forceFill([
                'grn_id' => $grn->id,
                'purchase_order_item_id' => $poItem->id,
                'project_cost_head_id' => $poItem->project_cost_head_id,
                'material_id' => $poItem->material_id,
                'unit_id' => $poItem->unit_id,
                'ordered_qty' => $poItem->quantity,
                'previously_received_qty' => $poItem->received_qty,
                'po_remaining_qty' => $remaining->toQuantity(),
                'received_qty' => $received->toQuantity(),
                'rejected_qty' => $rejected->toQuantity(),
                'accepted_qty' => $received->minus($rejected)->toQuantity(),
                'extra_qty' => $extra->toQuantity(),
                'extra_reason' => $extra->isPositive() ? $reason->value : null,
                'extra_remarks' => $extra->isPositive() ? ($row['extra_remarks'] ?? null) : null,
                'supplier_challan_qty' => $challan,
                'rejection_reason' => $rejected->isPositive() ? $row['rejection_reason'] : null,
                'rate' => $poItem->rate,
            ]);
            $line->save();
            $lines[$index] = $line;
            if ($extra->isPositive()) {
                $extras[] = [
                    'description' => $poItem->description,
                    'ordered' => $poItem->quantity,
                    'previously_received' => $poItem->received_qty,
                    'po_remaining' => $remaining->toQuantity(),
                    'received' => $received->toQuantity(),
                    'accepted' => $line->accepted_qty,
                    'extra' => $extra->toQuantity(),
                    'reason' => $reason->value,
                    'remarks' => $row['extra_remarks'] ?? null,
                ];
            }
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Enter the received quantity for at least one line.']);
        }
        if ($extras !== [] && ! $confirmed) {
            throw ValidationException::withMessages(['confirm_extra' => 'Confirm that the extra quantity should be received into stock.']);
        }

        $grn->forceFill(['extra_confirmed' => $extras !== []])->save();
        if ($extras !== []) {
            $this->audit->record($grn, 'over_receipt', null, ['lines' => $extras, 'user_id' => auth()->id(), 'at' => now()->toIso8601String()]);
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

    private function byUuid(PurchaseOrder $po, string $uuid): ?Grn
    {
        $existing = Grn::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();
        if ($existing === null) {
            return null;
        }

        $mine = Grn::query()->whereKey($existing->id)->where('purchase_order_id', $po->id)->first();

        return $mine ?? throw ValidationException::withMessages(['uuid' => 'This GRN reference is already in use.']);
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

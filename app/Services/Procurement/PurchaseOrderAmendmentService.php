<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\PurchaseOrderRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approved orders are never edited in place (architecture I.3): amending snapshots the approved
 * version into purchase_order_revisions, increments revision_no and reopens the order as a draft
 * that must be approved again. Received quantities and GRNs are kept.
 */
class PurchaseOrderAmendmentService
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function amend(PurchaseOrder $po, string $reason, User $user): PurchaseOrderRevision
    {
        return DB::transaction(function () use ($po, $reason, $user) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only approved or partially received orders can be amended.']);
            }
            $this->orders->assertNoPendingGrns($po);

            $revision = (new PurchaseOrderRevision)->forceFill([
                'purchase_order_id' => $po->id,
                'revision_no' => $po->revision_no,
                'snapshot' => $this->snapshot($po),
                'reason' => $reason,
                'created_by' => $user->id,
            ]);
            $revision->save();

            $from = $po->revision_no;
            $po->forceFill([
                'status' => PurchaseOrderStatus::Draft,
                'revision_no' => $from + 1,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();
            $po->writeAudit('amended', ['revision_no' => $from], ['revision_no' => $from + 1, 'reason' => $reason]);

            return $revision;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(PurchaseOrder $po): array
    {
        $header = collect($po->getAttributes())->except(['id', 'company_id', 'created_at', 'updated_at', 'deleted_at', 'deleted_by'])->all();

        return [
            'header' => $header,
            'items' => $po->items()->get()->map(fn (PurchaseOrderItem $item) => collect($item->getAttributes())
                ->except(['purchase_order_id', 'created_at', 'updated_at'])->all())->all(),
        ];
    }
}

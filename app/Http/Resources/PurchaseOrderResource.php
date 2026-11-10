<?php

namespace App\Http\Resources;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Procurement\Grn;
use App\Models\Procurement\PurchaseOrder;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'revision_no' => $this->revision_no,
            'po_date' => $this->po_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tax_type' => $this->tax_type?->value,
            'place_of_supply_state' => $this->place_of_supply_state,
            'payment_terms' => $this->payment_terms,
            'remarks' => $this->remarks,
            'direct_justification' => $this->direct_justification,
            'is_direct' => $this->rfq_id === null,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'taxable_amount' => $this->taxable_amount,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'freight_amount' => $this->freight_amount,
            'other_charges' => $this->other_charges,
            'round_off' => $this->round_off,
            'grand_total' => $this->grand_total,
            'items_count' => $this->whenCounted('items'),
            'vendor' => $this->whenLoaded('vendor', function () {
                $vendor = $this->vendor;
                if ($vendor === null) {
                    return null;
                }

                return [
                    'id' => $vendor->id,
                    'code' => $vendor->code,
                    'name' => $vendor->name,
                    'gstin' => $vendor->getAttributes()['gstin'] ?? null,
                    'state_code' => $vendor->getAttributes()['state_code'] ?? null,
                    'city' => $vendor->getAttributes()['city'] ?? null,
                    'payment_terms' => $vendor->getAttributes()['payment_terms'] ?? null,
                ];
            }),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->only(['id', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($line) {
                $open = Decimal::of($line->quantity)->minus($line->received_qty);
                $extra = Decimal::of($line->received_qty)->minus($line->quantity);

                return [
                    'id' => $line->id,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'hsn_sac' => $line->hsn_sac,
                    'quantity' => $line->quantity,
                    'rate' => $line->rate,
                    'discount_percent' => $line->discount_percent,
                    'taxable_amount' => $line->taxable_amount,
                    'cgst_amount' => $line->cgst_amount,
                    'sgst_amount' => $line->sgst_amount,
                    'igst_amount' => $line->igst_amount,
                    'amount' => $line->amount,
                    'received_qty' => $line->received_qty,
                    'remaining_qty' => ($open->isNegative() ? Decimal::zero() : $open)->toQuantity(),
                    'over_received_qty' => ($extra->isNegative() ? Decimal::zero() : $extra)->toQuantity(),
                    'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                    'tax_rate' => $line->relationLoaded('taxRate') ? $line->taxRate?->only(['id', 'name', 'rate']) : null,
                    'material_request_item_id' => $line->material_request_item_id,
                    'material_request' => $line->relationLoaded('materialRequestItem')
                        ? $line->materialRequestItem?->materialRequest?->only(['id', 'request_number'])
                        : null,
                    'item' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name']) : null,
                ];
            })->values()),
            'revisions' => $this->whenLoaded('revisions', fn () => $this->revisions->map(fn ($r) => [
                'id' => $r->id,
                'revision_no' => $r->revision_no,
                'reason' => $r->reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'can' => $user ? $this->abilities($user) : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(\App\Models\User $user): array
    {
        $editable = $this->isEditable();
        $status = $this->status;
        $amendable = in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true);
        $cancellable = in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Rejected], true)
            || ($status === PurchaseOrderStatus::Draft && $this->revision_no > 0);
        $closable = in_array($status, [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], true);
        $receivable = in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true);
        $export = $user->can('export', $this->resource);

        return [
            'view' => $user->can('view', $this->resource),
            'update' => $editable && $user->can('update', $this->resource),
            'delete' => $editable && $this->revision_no === 0 && $user->can('delete', $this->resource),
            'submit' => $editable && $user->can('submit', $this->resource),
            'amend' => $amendable && $user->can('amend', $this->resource),
            'cancel' => $cancellable && $user->can('cancel', $this->resource),
            'close' => $closable && $user->can('close', $this->resource),
            'create_grn' => $receivable && $user->can('create', [Grn::class, $this->resource]),
            'view_pdf' => $export,
            'download_pdf' => $export,
            'attach' => $user->can('update', $this->resource),
            'approve' => (bool) ($this->relationLoaded('latestApprovalRequest')
                && $this->latestApprovalRequest?->status === \App\Enums\Approval\ApprovalStatus::Pending
                && $user->can('approvals.view')),
            'reject' => (bool) ($this->relationLoaded('latestApprovalRequest')
                && $this->latestApprovalRequest?->status === \App\Enums\Approval\ApprovalStatus::Pending
                && $user->can('approvals.view')),
        ];
    }
}

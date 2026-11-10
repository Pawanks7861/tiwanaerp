<?php

namespace App\Http\Resources;

use App\Models\Procurement\Grn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Grn
 */
class GrnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'grn_number' => $this->grn_number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'purchase_order_id' => $this->purchase_order_id,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'vendor_invoice_date' => $this->vendor_invoice_date?->toDateString(),
            'vendor_challan_no' => $this->vendor_challan_no,
            'vehicle_no' => $this->vehicle_no,
            'remarks' => $this->remarks,
            'extra_confirmed' => (bool) $this->extra_confirmed,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder?->id,
                'po_number' => $this->purchaseOrder?->po_number,
                'status' => $this->purchaseOrder?->status?->value,
            ]),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'code', 'name', 'gstin'])),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse?->id,
                'code' => $this->warehouse?->code,
                'name' => $this->warehouse?->name,
                'type' => $this->warehouse?->type?->value ?? $this->warehouse?->type,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'ordered_qty' => $line->ordered_qty,
                'previously_received_qty' => $line->previously_received_qty,
                'po_remaining_qty' => $line->po_remaining_qty,
                'received_qty' => $line->received_qty,
                'rejected_qty' => $line->rejected_qty,
                'accepted_qty' => $line->accepted_qty,
                'extra_qty' => $line->extra_qty,
                'extra_reason' => $line->extra_reason,
                'extra_remarks' => $line->extra_remarks,
                'rejection_reason' => $line->rejection_reason,
                'supplier_challan_qty' => $line->supplier_challan_qty,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'item' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name']) : null,
            ])->values()),
            'weighbridge' => $this->whenLoaded('weighbridgeReceipt', fn () => $this->weighbridgeReceipt
                ? WeighbridgeReceiptResource::make($this->weighbridgeReceipt)->resolve()
                : null),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'challan' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->firstWhere('category', 'challan')
                ?->only(['id', 'original_name', 'mime'])),
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

        return [
            'view' => $user->can('view', $this->resource),
            'update' => $editable && $user->can('update', $this->resource),
            'delete' => $editable && $user->can('delete', $this->resource),
            'submit' => $editable && $user->can('submit', $this->resource),
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

<?php

namespace App\Http\Resources;

use App\Enums\Finance\VendorBillStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\VendorBill;
use App\Services\Finance\PayableService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorBill
 */
class VendorBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $amounts = $user && FinancePresenter::seesVendorBills($user);
        $editable = $this->isEditable();
        $submitted = $this->status === VendorBillStatus::Submitted;
        $approved = $this->status->isApproved();
        $payables = $amounts && $approved ? app(PayableService::class) : null;

        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,
            'bill_type' => $this->bill_type->value,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'vendor_invoice_date' => $this->vendor_invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tax_type' => $this->tax_type->value,
            'subtotal' => $this->when($amounts, $this->subtotal),
            'tax_amount' => $this->when($amounts, $this->tax_amount),
            'total_amount' => $this->when($amounts, $this->total_amount),
            'tds_percent' => $this->when($amounts, $this->tds_percent),
            'tds_amount' => $this->when($amounts, $this->tds_amount),
            'net_payable' => $this->when($amounts, $this->net_payable),
            'paid_amount' => $this->when($amounts && $approved, $this->paid_amount),
            'outstanding' => $this->when($amounts && $approved && $payables, fn () => $payables->outstanding($this->resource)->toMoney()),
            'remarks' => $this->remarks,
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->id,
                'code' => $this->vendor->code,
                'name' => $this->vendor->name,
                'gstin' => $this->vendor->gstin,
            ] : null),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'grn_item_id' => $line->grn_item_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'rate' => $this->when($amounts, $line->rate),
                'amount' => $this->when($amounts, $line->amount),
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'previous_billed_qty' => $line->getAttributes()['previous_billed_qty'] ?? null,
                'available_qty' => $line->getAttributes()['available_qty'] ?? null,
                'po_qty' => $line->relationLoaded('purchaseOrderItem') ? $line->purchaseOrderItem?->quantity : null,
                'grn_accepted_qty' => $line->relationLoaded('grnItem') ? $line->grnItem?->accepted_qty : null,
            ])->values()),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'matching' => $this->when($this->offsetExists('matching'), fn () => $this->offsetGet('matching')),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'processing_reference' => $this->processing_reference,
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'process' => $user->can('process', $this->resource),
                'view_amounts' => $amounts,
            ] : null,
        ];
    }
}

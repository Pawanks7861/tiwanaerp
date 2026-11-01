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
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'grn_number' => $this->grn_number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'purchase_order_id' => $this->purchase_order_id,
            'remarks' => $this->remarks,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder?->only(['id', 'po_number'])),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'code', 'name'])),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->only(['id', 'code', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'received_qty' => $line->received_qty,
                'rejected_qty' => $line->rejected_qty,
                'accepted_qty' => $line->accepted_qty,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'item' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name']) : null,
            ])->values()),
            'challan' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->firstWhere('category', 'challan')
                ?->only(['id', 'original_name', 'mime'])),
        ];
    }
}

<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'po_date' => $this->po_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'code', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'item_code' => $line->item_code,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'received_qty' => $line->received_qty,
                'remaining_qty' => Decimal::of($line->quantity)->minus($line->received_qty)->toQuantity(),
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'item' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name']) : null,
            ])->values()),
        ];
    }
}

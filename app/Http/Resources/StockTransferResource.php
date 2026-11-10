<?php

namespace App\Http\Resources;

use App\Enums\Inventory\StockTransferStatus;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $draft = $this->isEditable();

        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'vehicle_no' => $this->vehicle_no,
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn () => [
                'id' => $this->fromWarehouse?->id,
                'code' => $this->fromWarehouse?->code,
                'name' => $this->fromWarehouse?->name,
                'type' => $this->fromWarehouse?->getAttributes()['type'] ?? null,
            ]),
            'to_warehouse' => $this->whenLoaded('toWarehouse', fn () => [
                'id' => $this->toWarehouse?->id,
                'code' => $this->toWarehouse?->code,
                'name' => $this->toWarehouse?->name,
                'type' => $this->toWarehouse?->getAttributes()['type'] ?? null,
            ]),
            'from_store' => $this->whenLoaded('fromWarehouse', fn () => $this->fromWarehouse?->name),
            'to_store' => $this->whenLoaded('toWarehouse', fn () => $this->toWarehouse?->name),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'received_qty' => $line->received_qty,
                'short_closed_qty' => $line->short_closed_qty,
                'remarks' => $line->remarks,
                'material' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name', 'item_type']) : null,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
            ])->values()),
            'receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn ($r) => [
                'id' => $r->id,
                'idempotency_key' => $r->idempotency_key,
                'receipt_date' => $r->receipt_date?->toDateString(),
                'remarks' => $r->remarks,
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $draft && $user->can('update', $this->resource),
                'delete' => $draft && $user->can('delete', $this->resource),
                'dispatch' => $draft && $user->can('dispatch', $this->resource),
                'receive' => $this->status->isInTransit() && $user->can('receive', $this->resource),
                'cancel' => $this->status === StockTransferStatus::Dispatched && $user->can('cancel', $this->resource),
                'close_short' => $this->status === StockTransferStatus::PartiallyReceived && $user->can('closeShort', $this->resource),
                'attach' => $draft && $user->can('update', $this->resource),
                'view_valuation' => InventoryPresenter::seesValuation($user),
            ] : null,
        ];
    }
}

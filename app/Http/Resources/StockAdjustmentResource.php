<?php

namespace App\Http\Resources;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockAdjustment
 */
class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $valuation = $user ? InventoryPresenter::seesValuation($user) : false;
        $editable = $this->isEditable();
        $submitted = $this->status === InventoryDocumentStatus::Submitted;
        $notSubmitter = $user && (int) $this->submitted_by !== (int) $user->id;

        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse?->id,
                'code' => $this->warehouse?->code,
                'name' => $this->warehouse?->name,
                'type' => $this->warehouse?->getAttributes()['type'] ?? null,
            ]),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'system_qty' => $line->system_qty,
                'physical_qty' => $line->physical_qty,
                'difference' => $line->difference,
                'remarks' => $line->remarks,
                'material' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name', 'item_type']) : null,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'unit_cost' => $this->when($valuation, $line->unit_cost),
                'value' => $this->when($valuation, $line->value),
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'approve' => $submitted && $notSubmitter && $user->can('approve', $this->resource),
                'reject' => $submitted && $notSubmitter && $user->can('reject', $this->resource),
                'cancel' => $this->status === InventoryDocumentStatus::Approved && $user->can('cancel', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
                'view_valuation' => $valuation,
            ] : null,
        ];
    }
}

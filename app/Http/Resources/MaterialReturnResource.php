<?php

namespace App\Http\Resources;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\MaterialReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialReturn
 */
class MaterialReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $valuation = $user ? InventoryPresenter::seesValuation($user) : false;
        $editable = $this->isEditable();

        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'return_date' => $this->return_date?->toDateString(),
            'type' => $this->return_type->value,
            'type_label' => $this->return_type->label(),
            'reason' => $this->reason,
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
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'code', 'name'])),
            'grn' => $this->whenLoaded('grn', fn () => $this->grn?->only(['id', 'grn_number'])),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'remarks' => $line->remarks,
                'material_issue_item_id' => $line->material_issue_item_id,
                'grn_item_id' => $line->grn_item_id,
                'material' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name', 'item_type']) : null,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'unit_cost' => $this->when($valuation, $line->getAttributes()['unit_cost'] ?? null),
                'value' => $this->when($valuation, $line->getAttributes()['value'] ?? null),
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'cancel' => $this->status === InventoryDocumentStatus::Approved && $user->can('cancel', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
                'view_valuation' => $valuation,
            ] : null,
        ];
    }
}

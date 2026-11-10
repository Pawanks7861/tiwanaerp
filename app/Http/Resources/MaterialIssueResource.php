<?php

namespace App\Http\Resources;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\MaterialIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialIssue
 */
class MaterialIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $valuation = $user ? InventoryPresenter::seesValuation($user) : false;
        $editable = $this->isEditable();
        $posted = $this->status === InventoryDocumentStatus::Approved;

        return [
            'id' => $this->id,
            'issue_number' => $this->issue_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'purpose' => $this->purpose,
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issued_to' => $this->when(
                $this->relationLoaded('issuedToUser') || $this->relationLoaded('subcontractor') || $this->issued_to_name,
                fn () => $this->issuedToUser?->name ?? $this->subcontractor?->name ?? $this->issued_to_name
            ),
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
                'quantity' => $line->quantity,
                'remarks' => $line->remarks,
                'material' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name', 'item_type']) : null,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'task' => $line->relationLoaded('task') ? $line->task?->only(['id', 'wbs_code', 'name']) : null,
                'unit_cost' => $this->when($valuation, $line->unit_cost),
                'amount' => $this->when($valuation, $line->amount),
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
                'cancel' => $posted && $user->can('cancel', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
                'view_valuation' => $valuation,
            ] : null,
        ];
    }
}

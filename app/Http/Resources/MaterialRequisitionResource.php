<?php

namespace App\Http\Resources;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Procurement\MaterialRequest;
use App\Support\Procurement\ProcurementSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialRequest
 */
class MaterialRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'request_date' => $this->request_date?->toDateString(),
            'required_date' => $this->required_date?->toDateString(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remarks' => $this->remarks,
            'cancelled_reason' => $this->cancelled_reason,
            'items_count' => $this->whenCounted('items'),
            'site' => $this->whenLoaded('site', fn () => $this->site?->only(['id', 'name'])),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->only(['id', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'ordered_qty' => $line->ordered_qty,
                'received_qty' => $line->received_qty,
                'remarks' => $line->remarks,
                'boq_item_id' => $line->boq_item_id,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'item' => $this->itemMaster($line),
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
     * @return array{id: int, code: string, name: string}|null
     */
    private function itemMaster(mixed $line): ?array
    {
        $master = $line->relationLoaded('item') ? $line->item : ($line->relationLoaded('material') ? $line->material : null);

        return $master?->only(['id', 'code', 'name']);
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(\App\Models\User $user): array
    {
        $editable = $this->isEditable();
        $pending = $this->relationLoaded('latestApprovalRequest')
            && $this->latestApprovalRequest?->status === ApprovalStatus::Pending;

        return [
            'view' => $user->can('view', $this->resource),
            'update' => $editable && $user->can('update', $this->resource),
            'delete' => $editable && $user->can('delete', $this->resource),
            'submit' => $editable && $user->can('submit', $this->resource),
            'cancel' => $this->status->isProcurable() && $user->can('cancel', $this->resource),
            'attach' => $user->can('update', $this->resource),
            'create_po' => ! ProcurementSettings::rfqEnabled()
                && $this->status->isProcurable()
                && $user->can('purchase.create'),
            'approve' => $pending && $user->can('approvals.view'),
            'reject' => $pending && $user->can('approvals.view'),
        ];
    }
}

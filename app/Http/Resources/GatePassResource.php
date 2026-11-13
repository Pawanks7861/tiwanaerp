<?php

namespace App\Http\Resources;

use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassStatus;
use App\Models\Gate\GatePass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GatePass
 */
class GatePassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        $editable = $this->isEditable();

        return [
            'id' => $this->id,
            'pass_number' => $this->pass_number,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'returnable' => (bool) $this->returnable,
            'passed_at' => $this->passed_at?->toIso8601String(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'site_id' => $this->site_id,
            'site' => $this->whenLoaded('site', fn () => $this->site?->only(['id', 'name'])),
            'party_name' => $this->party_name,
            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'name'])),
            'vehicle_no' => $this->vehicle_no,
            'driver_name' => $this->driver_name,
            'driver_mobile' => $this->driver_mobile,
            'purpose' => $this->purpose,
            'reference_type' => $this->reference_type,
            'reference_no' => $this->reference_no,
            'remarks' => $this->remarks,
            'rejected_reason' => $this->rejected_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->only(['id', 'name'])),
            'verifier' => $this->whenLoaded('verifier', fn () => $this->verifier?->only(['id', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'item_type' => $item->item_type->value,
                'item_type_label' => $item->item_type->label(),
                'material_id' => $item->material_id,
                'equipment_id' => $item->equipment_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_id' => $item->unit_id,
                'weight' => $item->weight,
                'scrap_type' => $item->scrap_type,
                'scrap_type_id' => $item->scrap_type_id,
                'rate' => $item->rate,
                'destination' => $item->destination,
                'remarks' => $item->remarks,
                'material' => $item->relationLoaded('material') ? $item->material?->only(['id', 'code', 'name']) : null,
                'equipment' => $item->relationLoaded('equipment') && $item->equipment ? [
                    'id' => $item->equipment->id,
                    'code' => $item->equipment->code,
                    'name' => $item->equipment->name,
                ] : null,
                'unit' => $item->relationLoaded('unit') ? $item->unit?->only(['id', 'symbol']) : null,
            ])->values()),
            'weighbridge' => $this->when(
                $this->relationLoaded('weighbridgeReceipt') && $this->weighbridgeReceipt,
                fn () => WeighbridgeReceiptResource::make($this->weighbridgeReceipt)->resolve()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('update', $this->resource),
                'approve' => $status === GatePassStatus::Submitted && $user->can('approve', $this->resource),
                'reject' => $status === GatePassStatus::Submitted && $user->can('approve', $this->resource),
                'verify' => $status === GatePassStatus::Approved && $user->can('verify', $this->resource),
                'return' => $this->returnable
                    && $this->direction === GatePassDirection::Out
                    && in_array($status, [GatePassStatus::Verified, GatePassStatus::Approved], true)
                    && $user->can('verify', $this->resource),
                'view_pdf' => $user->can('view', $this->resource),
                'download_pdf' => $user->can('view', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
            ] : null,
        ];
    }
}

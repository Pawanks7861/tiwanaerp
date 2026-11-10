<?php

namespace App\Http\Resources;

use App\Http\Controllers\Equipment\EquipmentPresenter;
use App\Models\Equipment\EquipmentRepair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentRepair
 */
class EquipmentRepairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = $user && EquipmentPresenter::seesCosts($user);
        $open = $this->isOpen();

        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'repair_date' => $this->repair_date?->toDateString(),
            'completed_date' => $this->completed_date?->toDateString(),
            'description' => $this->description,
            'vendor_id' => $this->vendor_id,
            'cost' => $this->when($costs, $this->cost),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remarks' => $this->remarks,
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment ? [
                'id' => $this->equipment->id,
                'code' => $this->equipment->code,
                'name' => $this->equipment->name,
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor?->only(['id', 'name'])),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $open && $user->can('update', $this->resource),
                'complete' => $open && $user->can('complete', $this->resource),
                'cancel' => $open && $user->can('cancel', $this->resource),
                'attach' => $open && $user->can('update', $this->resource),
                'view_costs' => $costs,
            ] : null,
        ];
    }
}

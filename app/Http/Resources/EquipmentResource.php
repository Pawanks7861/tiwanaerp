<?php

namespace App\Http\Resources;

use App\Http\Controllers\Equipment\EquipmentPresenter;
use App\Models\Equipment\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = $user && EquipmentPresenter::seesCosts($user);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'registration_no' => $this->registration_no,
            'serial_no' => $this->serial_no,
            'make' => $this->make,
            'model' => $this->model,
            'manufacture_year' => $this->manufacture_year,
            'ownership' => $this->ownership?->value,
            'ownership_label' => $this->ownership?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'location_type' => $this->location_type?->value,
            'location_type_label' => $this->location_type?->label(),
            'location_project_id' => $this->location_project_id,
            'location_name' => $this->location_name,
            'location_notes' => $this->location_notes,
            'location_updated_at' => $this->location_updated_at?->toIso8601String(),
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_value' => $this->when($costs, $this->purchase_value),
            'hourly_rate' => $this->when($costs, $this->hourly_rate),
            'daily_rate' => $this->when($costs, $this->daily_rate),
            'remarks' => $this->remarks,
            'is_active' => (bool) $this->is_active,
            'equipment_type' => $this->whenLoaded('equipmentType', fn () => $this->equipmentType ? [
                'id' => $this->equipmentType->id,
                'name' => $this->equipmentType->name,
            ] : null),
            'owner_vendor' => $this->whenLoaded('ownerVendor', fn () => $this->ownerVendor ? [
                'id' => $this->ownerVendor->id,
                'name' => $this->ownerVendor->name,
            ] : null),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'view_costs' => $costs,
                'attach' => $user->can('update', $this->resource),
            ] : null,
        ];
    }
}

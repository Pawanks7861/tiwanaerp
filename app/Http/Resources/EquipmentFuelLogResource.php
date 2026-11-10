<?php

namespace App\Http\Resources;

use App\Http\Controllers\Equipment\EquipmentPresenter;
use App\Models\Equipment\EquipmentFuelLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentFuelLog
 */
class EquipmentFuelLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = $user && EquipmentPresenter::seesCosts($user);
        $locked = $this->material_issue_id !== null;

        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'log_date' => $this->log_date?->toDateString(),
            'opening_fuel' => $this->opening_fuel,
            'fuel_added' => $this->fuel_added,
            'fuel_consumed' => $this->fuel_consumed,
            'closing_fuel' => $this->closing_fuel,
            'fuel_rate' => $this->when($costs, $this->fuel_rate),
            'cost' => $this->when($costs, $this->cost),
            'material_id' => $this->material_id,
            'warehouse_id' => $this->warehouse_id,
            'site_id' => $this->site_id,
            'operator_name' => $this->operator_name,
            'meter_reading' => $this->meter_reading,
            'remarks' => $this->remarks,
            'material_issue_id' => $this->material_issue_id,
            'issue_locked' => $locked,
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment ? [
                'id' => $this->equipment->id,
                'code' => $this->equipment->code,
                'name' => $this->equipment->name,
            ] : null),
            'material' => $this->whenLoaded('material', fn () => $this->material?->only(['id', 'code', 'name'])),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol'])),
            'material_issue' => $this->whenLoaded('materialIssue', fn () => $this->materialIssue ? [
                'id' => $this->materialIssue->id,
                'issue_number' => $this->materialIssue->issue_number,
            ] : null),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => ! $locked && $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                'view_costs' => $costs,
            ] : null,
        ];
    }
}

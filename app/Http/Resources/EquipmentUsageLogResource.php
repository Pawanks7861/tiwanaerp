<?php

namespace App\Http\Resources;

use App\Http\Controllers\Equipment\EquipmentPresenter;
use App\Models\Equipment\EquipmentUsageLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentUsageLog
 */
class EquipmentUsageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = $user && EquipmentPresenter::seesCosts($user);
        $posted = $this->isPosted();

        return [
            'id' => $this->id,
            'equipment_assignment_id' => $this->equipment_assignment_id,
            'log_date' => $this->log_date?->toDateString(),
            'task_id' => $this->task_id,
            'opening_meter' => $this->opening_meter,
            'closing_meter' => $this->closing_meter,
            'working_hours' => $this->working_hours,
            'idle_hours' => $this->idle_hours,
            'cost_amount' => $this->when($costs, $this->cost_amount),
            'remarks' => $this->remarks,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'assignment' => $this->whenLoaded('assignment', fn () => $this->assignment ? [
                'id' => $this->assignment->id,
                'rate' => $this->when($costs, $this->assignment->rate),
                'rate_basis' => $this->assignment->rate_basis?->value,
                'rate_basis_label' => $this->assignment->rate_basis?->label(),
                'equipment' => $this->assignment->relationLoaded('equipment') && $this->assignment->equipment ? [
                    'id' => $this->assignment->equipment->id,
                    'code' => $this->assignment->equipment->code,
                    'name' => $this->assignment->equipment->name,
                ] : null,
            ] : null),
            'task' => $this->whenLoaded('task', fn () => $this->task?->only(['id', 'wbs_code', 'name'])),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => ! $posted && $user->can('update', $this->resource),
                'delete' => ! $posted && $user->can('delete', $this->resource),
                'reverse' => $posted && $user->can('reverse', $this->resource),
                'view_costs' => $costs,
            ] : null,
        ];
    }
}

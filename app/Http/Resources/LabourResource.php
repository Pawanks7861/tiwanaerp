<?php

namespace App\Http\Resources;

use App\Http\Controllers\Labour\LabourPresenter;
use App\Models\Labour\Labour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Labour
 */
class LabourResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $wages = $user && LabourPresenter::seesWages($user);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'is_active' => (bool) $this->is_active,
            'joining_date' => $this->joining_date?->toDateString(),
            'trade' => $this->whenLoaded('trade', fn () => $this->trade ? [
                'id' => $this->trade->id,
                'name' => $this->trade->name,
            ] : null),
            'subcontractor' => $this->whenLoaded('subcontractor', fn () => $this->subcontractor ? [
                'id' => $this->subcontractor->id,
                'code' => $this->subcontractor->code,
                'name' => $this->subcontractor->name,
            ] : null),
            'current_project_id' => $this->current_project_id,
            'current_project' => $this->whenLoaded('currentProject', fn () => $this->currentProject ? [
                'id' => $this->currentProject->id,
                'code' => $this->currentProject->code,
                'name' => $this->currentProject->name,
            ] : null),
            'daily_wage' => $this->when($wages, $this->daily_wage),
            'ot_rate_per_hour' => $this->when($wages, $this->ot_rate_per_hour),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'view_wages' => $wages,
            ] : null,
        ];
    }
}

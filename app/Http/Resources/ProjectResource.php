<?php

namespace App\Http\Resources;

use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'status' => $this->status->value,
            'client' => $this->whenLoaded('client', fn () => $this->client?->company_name),
            'start_date' => $this->start_date?->toDateString(),
            'expected_end_date' => $this->expected_end_date?->toDateString(),
            'contract_value' => $this->when($request->user()->can('dashboard.view_financials'), $this->contract_value),
            'sites' => $this->whenLoaded('sites', fn () => $this->sites->map(fn ($s) => $s->only([
                'id', 'name', 'latitude', 'longitude', 'geofence_radius_m', 'is_active',
            ]))),
        ];
    }
}

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
        $user = $request->user();

        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'project_type' => $this->project_type,
            'address' => $this->address,
            'city' => $this->city,
            'state_code' => $this->state_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'code' => $this->client->code,
                'company_name' => $this->client->company_name,
                'contact_person' => $this->client->contact_person,
                'mobile' => $this->client->mobile,
            ] : null),
            'project_manager' => $this->whenLoaded('projectManager', fn () => $this->projectManager ? [
                'id' => $this->projectManager->id,
                'name' => $this->projectManager->name,
            ] : null),
            'start_date' => $this->start_date?->toDateString(),
            'expected_end_date' => $this->expected_end_date?->toDateString(),
            'contract_value' => $this->when($user?->can('dashboard.view_financials'), $this->contract_value),
            'drawings_count' => $this->whenCounted('drawings'),
            'approved_drawings_count' => $this->whenCounted('approved_drawings'),
            'boqs_count' => $this->whenCounted('boqs'),
            'approved_boqs_count' => $this->whenCounted('approved_boqs'),
            'has_drawing' => $this->when(isset($this->drawings_count), fn () => (int) $this->drawings_count > 0),
            'has_approved_drawing' => $this->when(isset($this->approved_drawings_count), fn () => (int) $this->approved_drawings_count > 0),
            'has_boq' => $this->when(isset($this->boqs_count), fn () => (int) $this->boqs_count > 0),
            'has_approved_boq' => $this->when(isset($this->approved_boqs_count), fn () => (int) $this->approved_boqs_count > 0),
            'can' => $this->when($user !== null, fn () => [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'manage_team' => $user->can('manageTeam', $this->resource),
            ]),
            'sites' => $this->whenLoaded('sites', fn () => $this->sites->map(fn ($s) => $s->only([
                'id', 'name', 'latitude', 'longitude', 'geofence_radius_m', 'is_active',
            ]))),
        ];
    }
}

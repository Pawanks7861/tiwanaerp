<?php

namespace App\Services\Equipment;

use App\Enums\Equipment\EquipmentLocationType;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentLocationHistory;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Current location lives on the equipment row. Every change also appends a history row.
 */
class EquipmentLocationService
{
    /**
     * @param  array<string, mixed>  $data  location_type, location_project_id, location_name, location_notes, remarks
     */
    public function change(Equipment $equipment, array $data, ?User $user = null, ?string $remarks = null): Equipment
    {
        $before = $this->snapshot($equipment);
        $type = EquipmentLocationType::tryFrom((string) ($data['location_type'] ?? ''))
            ?? throw ValidationException::withMessages(['location_type' => 'Choose a location type.']);
        $projectId = $this->projectId($type, $data['location_project_id'] ?? null);

        $equipment->forceFill([
            'location_type' => $type,
            'location_project_id' => $projectId,
            'location_name' => $data['location_name'] ?? null,
            'location_notes' => $data['location_notes'] ?? null,
            'location_updated_at' => now(),
            'location_updated_by' => ($user ?? Auth::user())?->id,
        ])->save();

        $this->write($equipment, $before, $remarks ?? ($data['remarks'] ?? null), $user);

        return $equipment;
    }

    /**
     * Called after the equipment master form saves. Writes history only when the location changed.
     *
     * @param  array<string, mixed>  $before
     */
    public function recordIfChanged(Equipment $equipment, array $before): void
    {
        $current = $this->snapshot($equipment);
        $same = $this->value($current['location_type']) === $this->value($before['location_type'] ?? null)
            && (int) ($current['location_project_id'] ?? 0) === (int) ($before['location_project_id'] ?? 0)
            && (string) ($current['location_name'] ?? '') === (string) ($before['location_name'] ?? '');
        if ($same && ($before['location_type'] ?? null) !== null) {
            return;
        }
        if ($same && ($before['location_type'] ?? null) === null && EquipmentLocationHistory::query()->where('equipment_id', $equipment->id)->exists()) {
            return;
        }

        $this->write($equipment, [
            'location_type' => $before['location_type'] ?? null,
            'location_name' => $before['location_name'] ?? null,
            'location_project_id' => $before['location_project_id'] ?? null,
        ], $equipment->location_notes, Auth::user());
    }

    /**
     * @param  array{location_type: mixed, location_name: mixed, location_project_id: mixed}  $before
     */
    private function write(Equipment $equipment, array $before, ?string $remarks, ?User $user): void
    {
        $from = $before['location_type'] instanceof EquipmentLocationType
            ? $before['location_type']->value
            : $before['location_type'];

        (new EquipmentLocationHistory)->forceFill([
            'equipment_id' => $equipment->id,
            'from_location_type' => $from,
            'from_location_name' => $before['location_name'] ?? null,
            'from_project_id' => $before['location_project_id'] ?? null,
            'to_location_type' => $equipment->location_type?->value ?? $equipment->location_type,
            'to_location_name' => $equipment->location_name,
            'to_project_id' => $equipment->location_project_id,
            'remarks' => $remarks,
            'changed_at' => now(),
            'changed_by' => ($user ?? Auth::user())?->id,
        ])->save();

        if ($equipment->location_updated_at === null) {
            $equipment->forceFill([
                'location_updated_at' => now(),
                'location_updated_by' => ($user ?? Auth::user())?->id,
            ])->save();
        }
    }

    /**
     * @return array{location_type: mixed, location_name: mixed, location_project_id: mixed}
     */
    private function snapshot(Equipment $equipment): array
    {
        return [
            'location_type' => $equipment->location_type,
            'location_name' => $equipment->location_name,
            'location_project_id' => $equipment->location_project_id,
        ];
    }

    private function value(mixed $type): ?string
    {
        if ($type instanceof EquipmentLocationType) {
            return $type->value;
        }

        return $type === null || $type === '' ? null : (string) $type;
    }

    private function projectId(EquipmentLocationType $type, mixed $projectId): ?int
    {
        if ($type !== EquipmentLocationType::ProjectSite) {
            return null;
        }
        if (blank($projectId)) {
            throw ValidationException::withMessages(['location_project_id' => 'Choose the project site.']);
        }
        $project = Project::query()->whereKey((int) $projectId)->first();
        if ($project === null) {
            throw ValidationException::withMessages(['location_project_id' => 'Choose a project of this company.']);
        }

        return $project->id;
    }
}

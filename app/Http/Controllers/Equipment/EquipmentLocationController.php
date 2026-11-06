<?php

namespace App\Http\Controllers\Equipment;

use App\Enums\Equipment\EquipmentLocationType;
use App\Http\Controllers\Controller;
use App\Models\Equipment\Equipment;
use App\Services\Equipment\EquipmentLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EquipmentLocationController extends Controller
{
    public function __construct(private readonly EquipmentLocationService $locations) {}

    public function update(Request $request, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('update', $equipment);

        $data = $request->validate([
            'location_type' => ['required', Rule::enum(EquipmentLocationType::class)],
            'location_project_id' => ['nullable', 'integer'],
            'location_name' => ['nullable', 'string', 'max:150'],
            'location_notes' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->locations->change($equipment, $data, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', 'Equipment location updated.');
    }
}

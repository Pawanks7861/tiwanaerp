<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityMeterController extends Controller
{
    public function store(Request $request, ElectricityConnection $electricityConnection): RedirectResponse
    {
        Gate::authorize('create', ElectricityMeter::class);
        Gate::authorize('update', $electricityConnection);

        $data = $this->validated($request);
        ElectricityMeter::query()->create([
            ...$data,
            'electricity_connection_id' => $electricityConnection->id,
        ]);

        return back()->with('success', 'Meter added.');
    }

    public function update(Request $request, ElectricityMeter $electricityMeter): RedirectResponse
    {
        Gate::authorize('update', $electricityMeter);

        $electricityMeter->update($this->validated($request));

        return back()->with('success', 'Meter updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'meter_number' => ['required', 'string', 'max:60'],
            'meter_type' => ['nullable', 'string', 'max:40'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'multiplication_factor' => ['required', 'numeric', 'min:0'],
            'installed_at' => ['nullable', 'date'],
            'removed_at' => ['nullable', 'date', 'after_or_equal:installed_at'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}

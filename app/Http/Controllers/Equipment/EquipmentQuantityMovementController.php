<?php

namespace App\Http\Controllers\Equipment;

use App\Enums\Equipment\EquipmentQuantityMovementType;
use App\Enums\Equipment\EquipmentTrackingMode;
use App\Http\Controllers\Controller;
use App\Models\Equipment\Equipment;
use App\Services\Equipment\EquipmentQuantityMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EquipmentQuantityMovementController extends Controller
{
    public function __construct(private readonly EquipmentQuantityMovementService $movements) {}

    public function store(Request $request, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('update', $equipment);
        abort_unless(
            ($equipment->tracking_mode?->value ?? EquipmentTrackingMode::Individual->value) === EquipmentTrackingMode::Quantity->value,
            422,
            'Quantity movements apply only to quantity-tracked equipment.',
        );

        $data = $request->validate([
            'moved_at' => ['required', 'date', 'before_or_equal:today'],
            'movement_type' => ['required', Rule::in(array_column(EquipmentQuantityMovementType::manualOptions(), 'value'))],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999999'],
            'project_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->movements->record($equipment, $data, $request->user());

        return back()->with('success', 'Equipment quantity movement recorded.');
    }
}

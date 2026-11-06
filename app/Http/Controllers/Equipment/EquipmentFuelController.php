<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Equipment\Equipment;
use App\Models\Masters\Material;
use App\Support\Inventory\InventoryScope;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentFuelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentFuelController extends Controller
{
    public function __construct(
        private readonly EquipmentFuelService $fuel,
        private readonly InventoryScope $inventory,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [EquipmentFuelLog::class, $project]);
        $user = $request->user();

        $page = $project->equipmentFuelLogs()->with(['equipment:id,code,name', 'material:id,name', 'unit:id,symbol', 'materialIssue:id,issue_number'])
            ->latest('log_date')->latest('id')->paginate(50)->withQueryString();

        $equipmentIds = $project->equipmentAssignments()->distinct()->pluck('equipment_id');

        return Inertia::render('Equipment/Fuel', [
            'project' => ProjectHeader::for($project),
            'logs' => $page->through(fn (EquipmentFuelLog $l) => [
                'id' => $l->id,
                'equipment_id' => $l->equipment_id,
                'equipment' => $l->equipment ? "{$l->equipment->code} · {$l->equipment->name}" : null,
                'log_date' => $l->log_date?->toDateString(),
                'material' => $l->material?->name,
                'unit' => $l->unit?->symbol,
                'issue_number' => $l->materialIssue?->issue_number,
                'issue_url' => $l->material_issue_id && $user->can('inventory.view')
                    ? route('projects.material-issues.show', [$project, $l->material_issue_id])
                    : null,
                ...$l->only(['opening_fuel', 'fuel_added', 'fuel_consumed', 'closing_fuel', 'fuel_rate', 'cost', 'remarks', 'operator_name', 'meter_reading', 'material_id', 'warehouse_id', 'site_id']),
                'can_update' => $user->can('update', $l),
            ]),
            'options' => [
                'equipment' => Equipment::query()->whereIn('id', $equipmentIds)->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Equipment $e) => ['value' => $e->id, 'label' => $e->name, 'description' => $e->code])->all(),
                'materials' => Material::query()->active()->orderBy('name')->get(['id', 'code', 'name'])->map(fn (Material $m) => [
                    'value' => $m->id, 'label' => $m->name, 'description' => $m->code,
                ])->all(),
                'warehouses' => $this->inventory->options($project),
                'sites' => $project->sites()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all(),
            ],
            'today' => now()->toDateString(),
            'can' => ['create' => $user->can('create', [EquipmentFuelLog::class, $project])],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [EquipmentFuelLog::class, $project]);

        $this->fuel->create($project, $this->validated($request), $request->user());

        return back()->with('success', 'Fuel log saved.');
    }

    public function update(Request $request, Project $project, EquipmentFuelLog $equipmentFuelLog): RedirectResponse
    {
        Gate::authorize('update', $equipmentFuelLog);

        $this->fuel->update($equipmentFuelLog, $this->validated($request));

        return back()->with('success', 'Fuel log updated.');
    }

    public function destroy(Request $request, Project $project, EquipmentFuelLog $equipmentFuelLog): RedirectResponse
    {
        Gate::authorize('delete', $equipmentFuelLog);

        $this->fuel->delete($equipmentFuelLog, $request->user());

        return back()->with('success', 'Fuel log deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'equipment_id' => ['required', 'integer'],
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_fuel' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'fuel_added' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'fuel_consumed' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'material_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'operator_name' => ['nullable', 'string', 'max:120'],
            'meter_reading' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['equipment_id' => 'equipment']);
    }
}

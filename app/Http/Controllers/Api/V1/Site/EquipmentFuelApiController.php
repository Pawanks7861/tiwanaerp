<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentFuelLogResource;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentFuelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EquipmentFuelApiController extends Controller
{
    public function __construct(private readonly EquipmentFuelService $fuel) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [EquipmentFuelLog::class, $project]);

        $filters = $request->validate([
            'equipment_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = $project->equipmentFuelLogs()
            ->with(['equipment:id,code,name', 'material:id,code,name', 'unit:id,symbol', 'materialIssue:id,issue_number'])
            ->when(isset($filters['equipment_id']), fn ($q) => $q->where('equipment_id', (int) $filters['equipment_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('log_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('log_date', '<=', $filters['to']))
            ->latest('log_date')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return EquipmentFuelLogResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [EquipmentFuelLog::class, $project]);

        $log = $this->fuel->create($project, $this->validated($request), $request->user());

        return (new EquipmentFuelLogResource($this->detail($log)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, EquipmentFuelLog $equipmentFuelLog): EquipmentFuelLogResource
    {
        $this->assertOnProject($project, $equipmentFuelLog);
        Gate::authorize('view', $equipmentFuelLog);

        return new EquipmentFuelLogResource($this->detail($equipmentFuelLog));
    }

    public function update(Request $request, Project $project, EquipmentFuelLog $equipmentFuelLog): EquipmentFuelLogResource
    {
        $this->assertOnProject($project, $equipmentFuelLog);
        Gate::authorize('update', $equipmentFuelLog);

        $this->fuel->update($equipmentFuelLog, $this->validated($request));

        return new EquipmentFuelLogResource($this->detail($equipmentFuelLog->fresh()));
    }

    public function destroy(Request $request, Project $project, EquipmentFuelLog $equipmentFuelLog): JsonResponse
    {
        $this->assertOnProject($project, $equipmentFuelLog);
        Gate::authorize('delete', $equipmentFuelLog);

        $this->fuel->delete($equipmentFuelLog, $request->user());

        return response()->json(['message' => 'Fuel log deleted.']);
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

    private function detail(EquipmentFuelLog $log): EquipmentFuelLog
    {
        return $log->load(['equipment:id,code,name', 'material:id,code,name', 'unit:id,symbol', 'materialIssue:id,issue_number']);
    }

    private function assertOnProject(Project $project, EquipmentFuelLog $log): void
    {
        abort_unless((int) $log->project_id === (int) $project->id, 404);
    }
}

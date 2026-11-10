<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Equipment\RepairStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentRepairResource;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EquipmentRepairApiController extends Controller
{
    public function __construct(private readonly EquipmentRepairService $repairs) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [EquipmentRepair::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RepairStatus::class)],
            'equipment_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = $project->equipmentRepairs()
            ->with(['equipment:id,code,name', 'vendor:id,name'])
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['equipment_id']), fn ($q) => $q->where('equipment_id', (int) $filters['equipment_id']))
            ->latest('repair_date')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return EquipmentRepairResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [EquipmentRepair::class, $project]);

        $repair = $this->repairs->create($project, $request->validate([
            'equipment_id' => ['required', 'integer'],
            ...$this->rules(),
        ], [], ['equipment_id' => 'equipment']));

        return (new EquipmentRepairResource($this->detail($repair)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, EquipmentRepair $equipmentRepair): EquipmentRepairResource
    {
        $this->assertOnProject($project, $equipmentRepair);
        Gate::authorize('view', $equipmentRepair);

        return new EquipmentRepairResource($this->detail($equipmentRepair));
    }

    public function update(Request $request, Project $project, EquipmentRepair $equipmentRepair): EquipmentRepairResource
    {
        $this->assertOnProject($project, $equipmentRepair);
        Gate::authorize('update', $equipmentRepair);

        $this->repairs->update($equipmentRepair, $request->validate($this->rules()));

        return new EquipmentRepairResource($this->detail($equipmentRepair->fresh()));
    }

    public function complete(Request $request, Project $project, EquipmentRepair $equipmentRepair): EquipmentRepairResource
    {
        $this->assertOnProject($project, $equipmentRepair);
        Gate::authorize('complete', $equipmentRepair);

        $this->repairs->complete($equipmentRepair, $request->validate([
            'completed_date' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]));

        return new EquipmentRepairResource($this->detail($equipmentRepair->fresh()));
    }

    public function cancel(Request $request, Project $project, EquipmentRepair $equipmentRepair): EquipmentRepairResource
    {
        $this->assertOnProject($project, $equipmentRepair);
        Gate::authorize('cancel', $equipmentRepair);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->repairs->cancel($equipmentRepair, $reason);

        return new EquipmentRepairResource($this->detail($equipmentRepair->fresh()));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'repair_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:1000'],
            'vendor_id' => ['nullable', 'integer'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function detail(EquipmentRepair $repair): EquipmentRepair
    {
        return $repair->load(['equipment:id,code,name', 'vendor:id,name', 'attachments.uploader:id,name']);
    }

    private function assertOnProject(Project $project, EquipmentRepair $repair): void
    {
        abort_unless((int) $repair->project_id === (int) $project->id, 404);
    }
}

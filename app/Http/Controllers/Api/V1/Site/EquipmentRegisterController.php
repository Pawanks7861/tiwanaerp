<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Equipment\EquipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentResource;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentLocationHistory;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Equipment\EquipmentLocationService;
use App\Services\Masters\MasterService;
use App\Support\Masters\MasterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EquipmentRegisterController extends Controller
{
    public function __construct(
        private readonly MasterService $masters,
        private readonly EquipmentLocationService $locations,
    ) {}

    public function projectIndex(Request $request, Project $project): AnonymousResourceCollection
    {
        abort_unless(Gate::allows('view', $project) && $request->user()->can('equipment.view'), 403);

        return $this->index($request, $project);
    }

    public function index(Request $request, ?Project $project = null): AnonymousResourceCollection
    {
        $definition = MasterRegistry::get('equipment');
        if ($project === null) {
            Gate::authorize('viewAny', $definition->model());
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'equipment_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(EquipmentStatus::class)],
            'location_type' => ['nullable', Rule::enum(EquipmentLocationType::class)],
            'location_project_id' => ['nullable', 'integer'],
            'active' => ['nullable', Rule::in(['1', '0', 'true', 'false', 'yes', 'no'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $active = $filters['active'] ?? null;
        $projectId = $project?->id ?? ($filters['location_project_id'] ?? null);

        $query = Equipment::query()
            ->with($definition->with())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when(isset($filters['equipment_type_id']), fn ($q) => $q->where('equipment_type_id', (int) $filters['equipment_type_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['location_type']), fn ($q) => $q->where('location_type', $filters['location_type']))
            ->when($projectId, fn ($q) => $q->where(function ($w) use ($projectId) {
                $w->where('location_project_id', $projectId)
                    ->orWhereHas('assignments', fn ($a) => $a->where('project_id', $projectId)->where('status', 'active'));
            }))
            ->when($active !== null, fn ($q) => $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('is_active')
            ->orderBy('name');

        return EquipmentResource::collection(
            $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
        );
    }

    public function show(Equipment $equipment): EquipmentResource
    {
        Gate::authorize('view', $equipment);

        return new EquipmentResource($equipment->load(['equipmentType:id,name', 'ownerVendor:id,name', 'attachments.uploader:id,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $definition = MasterRegistry::get('equipment');
        Gate::authorize('create', $definition->model());

        $data = $request->validate($definition->rules(null));
        $record = $this->masters->create($definition, $data);

        return (new EquipmentResource($record->load(['equipmentType:id,name', 'ownerVendor:id,name'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Equipment $equipment): EquipmentResource
    {
        Gate::authorize('update', $equipment);
        $definition = MasterRegistry::get('equipment');
        $data = $request->validate($definition->rules($equipment));
        $record = $this->masters->update($definition, $equipment, $data);

        return new EquipmentResource($record->load(['equipmentType:id,name', 'ownerVendor:id,name']));
    }

    public function changeLocation(Request $request, Equipment $equipment): EquipmentResource
    {
        Gate::authorize('update', $equipment);

        $data = $request->validate([
            'location_type' => ['required', Rule::enum(EquipmentLocationType::class)],
            'location_project_id' => ['nullable', 'integer', new ExistsInCompany(Project::class)],
            'location_name' => ['nullable', 'string', 'max:150'],
            'location_notes' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $record = $this->locations->change($equipment, $data, $request->user(), $data['remarks'] ?? null);

        return new EquipmentResource($record->load(['equipmentType:id,name', 'ownerVendor:id,name']));
    }

    public function locationHistory(Equipment $equipment): JsonResponse
    {
        Gate::authorize('view', $equipment);

        $rows = $equipment->locationHistory()
            ->with(['toProject:id,code,name', 'changer:id,name'])
            ->paginate(50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (EquipmentLocationHistory $row) => [
                'id' => $row->id,
                'from_location_type' => $row->from_location_type?->value,
                'from_location_name' => $row->from_location_name,
                'to_location_type' => $row->to_location_type?->value,
                'to_location_name' => $row->to_location_name,
                'to_project' => $row->toProject ? $row->toProject->only(['id', 'code', 'name']) : null,
                'remarks' => $row->remarks,
                'changed_at' => $row->changed_at?->toIso8601String(),
                'changed_by' => $row->changer?->only(['id', 'name']),
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentUsageLogResource;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EquipmentUsageApiController extends Controller
{
    public function __construct(private readonly EquipmentUsageService $usage) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [EquipmentUsageLog::class, $project]);

        $filters = $request->validate([
            'state' => ['nullable', 'in:posted,unposted'],
            'assignment_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = $project->equipmentUsageLogs()
            ->with(['assignment.equipment:id,code,name', 'task:id,wbs_code,name'])
            ->when(($filters['state'] ?? null) === 'posted', fn ($q) => $q->whereNotNull('posted_at'))
            ->when(($filters['state'] ?? null) === 'unposted', fn ($q) => $q->whereNull('posted_at'))
            ->when($filters['assignment_id'] ?? null, fn ($q, $id) => $q->where('equipment_assignment_id', $id))
            ->latest('log_date')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return EquipmentUsageLogResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [EquipmentUsageLog::class, $project]);

        $log = $this->usage->create($project, $this->validated($request, true));

        return (new EquipmentUsageLogResource($this->detail($log)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, EquipmentUsageLog $equipmentUsageLog): EquipmentUsageLogResource
    {
        $this->assertOnProject($project, $equipmentUsageLog);
        Gate::authorize('view', $equipmentUsageLog);

        return new EquipmentUsageLogResource($this->detail($equipmentUsageLog));
    }

    public function update(Request $request, Project $project, EquipmentUsageLog $equipmentUsageLog): EquipmentUsageLogResource
    {
        $this->assertOnProject($project, $equipmentUsageLog);
        Gate::authorize('update', $equipmentUsageLog);

        $this->usage->update($equipmentUsageLog, $this->validated($request, false));

        return new EquipmentUsageLogResource($this->detail($equipmentUsageLog->fresh()));
    }

    public function destroy(Project $project, EquipmentUsageLog $equipmentUsageLog): JsonResponse
    {
        $this->assertOnProject($project, $equipmentUsageLog);
        Gate::authorize('delete', $equipmentUsageLog);

        $this->usage->delete($equipmentUsageLog);

        return response()->json(['message' => 'Usage log deleted.']);
    }

    public function post(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('post', [EquipmentUsageLog::class, $project]);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = $this->usage->post($project, $ids, $request->user());

        return response()->json([
            'message' => $count > 0 ? "{$count} usage log(s) posted." : 'Nothing to post.',
            'posted' => $count,
        ]);
    }

    public function reverse(Request $request, Project $project, EquipmentUsageLog $equipmentUsageLog): EquipmentUsageLogResource
    {
        $this->assertOnProject($project, $equipmentUsageLog);
        Gate::authorize('reverse', $equipmentUsageLog);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->usage->reverse($equipmentUsageLog, $request->user(), $reason);

        return new EquipmentUsageLogResource($this->detail($equipmentUsageLog->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? ['equipment_assignment_id' => ['required', 'integer']] : []),
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'task_id' => ['nullable', 'integer'],
            'opening_meter' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'closing_meter' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'working_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'idle_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['equipment_assignment_id' => 'equipment assignment']);
    }

    private function detail(EquipmentUsageLog $log): EquipmentUsageLog
    {
        return $log->load(['assignment.equipment:id,code,name', 'task:id,wbs_code,name']);
    }

    private function assertOnProject(Project $project, EquipmentUsageLog $log): void
    {
        abort_unless((int) $log->project_id === (int) $project->id, 404);
    }
}

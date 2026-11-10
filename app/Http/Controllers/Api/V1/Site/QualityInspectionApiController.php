<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Quality\CheckpointResult;
use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QualityInspectionResource;
use App\Models\Projects\Project;
use App\Models\Quality\QualityInspection;
use App\Services\Quality\QualityInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class QualityInspectionApiController extends Controller
{
    public function __construct(private readonly QualityInspectionService $inspections) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [QualityInspection::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InspectionStatus::class)],
            'result' => ['nullable', Rule::enum(InspectionResult::class)],
            'checklist' => ['nullable', 'integer'],
            'inspector' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'inspection_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $location = trim((string) ($filters['location'] ?? ''));

        $page = $project->qualityInspections()
            ->with(['checklist:id,name', 'site:id,name', 'engineer:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->when($filters['checklist'] ?? null, fn ($q, $id) => $q->where('quality_checklist_id', $id))
            ->when($filters['inspector'] ?? null, fn ($q, $id) => $q->where('engineer_id', $id))
            ->when($location !== '', fn ($q) => $q->where('location', 'like', '%'.addcslashes($location, '%_\\').'%'))
            ->when($filters['inspection_date'] ?? null, fn ($q, $d) => $q->whereDate('inspection_date', $d))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('inspection_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('inspection_date', '<=', $d))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('inspection_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('location', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return QualityInspectionResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [QualityInspection::class, $project]);

        $data = $request->validate([
            'quality_checklist_id' => ['required', 'integer'],
            ...$this->detailRules(),
        ], [], ['quality_checklist_id' => 'checklist']);

        $inspection = $this->inspections->create($project, $data, $request->user());

        return (new QualityInspectionResource($this->detail($inspection)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, QualityInspection $qualityInspection): QualityInspectionResource
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('view', $qualityInspection);

        return new QualityInspectionResource($this->detail($qualityInspection));
    }

    public function update(Request $request, Project $project, QualityInspection $qualityInspection): QualityInspectionResource
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('edit', $qualityInspection);

        $this->inspections->update($qualityInspection, $request->validate($this->detailRules()));

        return new QualityInspectionResource($this->detail($qualityInspection->fresh()));
    }

    public function destroy(Project $project, QualityInspection $qualityInspection): JsonResponse
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('delete', $qualityInspection);

        $this->inspections->delete($qualityInspection);

        return response()->json(['message' => 'Inspection deleted.']);
    }

    public function schedule(Request $request, Project $project, QualityInspection $qualityInspection): QualityInspectionResource
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('schedule', $qualityInspection);

        $data = $request->validate([
            'inspection_date' => ['required', 'date'],
            'engineer_id' => ['nullable', 'integer'],
        ]);
        $this->inspections->schedule($qualityInspection, $data);

        return new QualityInspectionResource($this->detail($qualityInspection->fresh()));
    }

    public function record(Request $request, Project $project, QualityInspection $qualityInspection): QualityInspectionResource
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('perform', $qualityInspection);

        $this->inspections->record($qualityInspection, $request->validate($this->itemRules())['items']);

        return new QualityInspectionResource($this->detail($qualityInspection->fresh()));
    }

    public function complete(Request $request, Project $project, QualityInspection $qualityInspection): QualityInspectionResource
    {
        $this->assertInspection($project, $qualityInspection);
        Gate::authorize('perform', $qualityInspection);

        $data = $request->validate([
            'result' => ['nullable', Rule::enum(InspectionResult::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(required: false),
        ]);
        $this->inspections->complete($qualityInspection, $data, $request->user());

        return new QualityInspectionResource($this->detail($qualityInspection->fresh()));
    }

    private function detail(QualityInspection $inspection): QualityInspection
    {
        return $inspection->load([
            'project:id,company_id',
            'checklist:id,name',
            'site:id,name',
            'task:id,wbs_code,name',
            'boqItem:id,item_code,name',
            'requester:id,name',
            'engineer:id,name',
            'completer:id,name',
            'items',
            'ncrs:id,quality_inspection_id,ncr_number,status,severity',
            'attachments.uploader:id,name',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRules(): array
    {
        return [
            'site_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'task_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'request_notes' => ['nullable', 'string', 'max:1000'],
            'inspection_date' => ['nullable', 'date'],
            'engineer_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(bool $required = true): array
    {
        return [
            'items' => [$required ? 'required' : 'nullable', 'array', 'max:200'],
            'items.*.result' => ['nullable', Rule::enum(CheckpointResult::class)],
            'items.*.remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function assertInspection(Project $project, QualityInspection $inspection): void
    {
        abort_unless((int) $inspection->project_id === (int) $project->id, 404);
    }
}

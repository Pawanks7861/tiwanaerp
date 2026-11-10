<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\MaterialRequestRequest;
use App\Http\Resources\MaterialRequisitionResource;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Services\Procurement\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MaterialRequisitionController extends Controller
{
    public function __construct(private readonly MaterialRequestService $requests) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialRequest::class, $project]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 25);

        $rows = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with(['site:id,name', 'requester:id,name'])
            ->withCount('items')
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($term) {
                    $w->where('request_number', 'like', $term)
                        ->orWhereHas('requester', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('items.material', fn ($m) => $m->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['priority']), fn ($q) => $q->where('priority', $filters['priority']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('request_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('request_date', '<=', $filters['to']))
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return MaterialRequisitionResource::collection($rows);
    }

    public function store(MaterialRequestRequest $request, Project $project): JsonResponse
    {
        $mr = $this->requests->create($project, $request->validated(), $request->user());

        return (new MaterialRequisitionResource($this->detail($mr)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, int $material_requisition): MaterialRequisitionResource
    {
        $mr = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->findOrFail($material_requisition);

        Gate::authorize('view', $mr);

        return new MaterialRequisitionResource($this->detail($mr));
    }

    public function update(MaterialRequestRequest $request, Project $project, MaterialRequest $materialRequest): MaterialRequisitionResource
    {
        abort_unless((int) $materialRequest->project_id === (int) $project->id, 404);

        $mr = $this->requests->update($materialRequest, $request->validated());

        return new MaterialRequisitionResource($this->detail($mr));
    }

    public function submit(Request $request, Project $project, MaterialRequest $materialRequest): MaterialRequisitionResource
    {
        abort_unless((int) $materialRequest->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $materialRequest);
        $this->requests->submit($materialRequest, $request->user());

        return new MaterialRequisitionResource($this->detail($materialRequest->fresh()));
    }

    public function cancel(Request $request, Project $project, MaterialRequest $materialRequest): MaterialRequisitionResource
    {
        abort_unless((int) $materialRequest->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $materialRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->requests->cancel($materialRequest, $data['reason']);

        return new MaterialRequisitionResource($this->detail($materialRequest->fresh()));
    }

    private function detail(MaterialRequest $mr): MaterialRequest
    {
        return $mr->load([
            'site:id,name',
            'requester:id,name',
            'items.material:id,code,name',
            'items.unit:id,symbol',
            'attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ])->loadCount('items');
    }
}

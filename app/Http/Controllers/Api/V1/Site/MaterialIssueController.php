<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\MaterialIssueResource;
use App\Models\Inventory\MaterialIssue;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Inventory\MaterialIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialIssueController extends Controller
{
    public function __construct(private readonly MaterialIssueService $issues) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialIssue::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = MaterialIssue::query()
            ->where('project_id', $project->id)
            ->with(['warehouse:id,code,name', 'issuedToUser:id,name', 'subcontractor:id,name'])
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('issue_number', 'like', $term)
                    ->orWhere('issued_to_name', 'like', $term)
                    ->orWhereHas('issuedToUser', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('items.material', fn ($m) => $m->where('name', 'like', $term)->orWhere('code', 'like', $term)));
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return MaterialIssueResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [MaterialIssue::class, $project]);
        $issue = $this->issues->create($project, $this->validated($request, $project));

        return (new MaterialIssueResource($this->detail($issue)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, MaterialIssue $materialIssue): MaterialIssueResource
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);
        Gate::authorize('view', $materialIssue);

        return new MaterialIssueResource($this->detail($materialIssue));
    }

    public function update(Request $request, Project $project, MaterialIssue $materialIssue): MaterialIssueResource
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);
        Gate::authorize('update', $materialIssue);
        $issue = $this->issues->update($materialIssue, $this->validated($request, $project));

        return new MaterialIssueResource($this->detail($issue));
    }

    public function submit(Request $request, Project $project, MaterialIssue $materialIssue): MaterialIssueResource
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $materialIssue);
        $this->issues->submit($materialIssue, $request->user());

        return new MaterialIssueResource($this->detail($materialIssue->fresh()));
    }

    public function cancel(Request $request, Project $project, MaterialIssue $materialIssue): MaterialIssueResource
    {
        abort_unless((int) $materialIssue->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $materialIssue);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->issues->cancel($materialIssue, $request->user(), $reason);

        return new MaterialIssueResource($this->detail($materialIssue->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'issued_to_user_id' => ['nullable', 'integer', Rule::exists('project_users', 'user_id')->where('project_id', $project->id)->where('is_active', true)],
            'subcontractor_id' => ['nullable', ExistsInCompany::active(Subcontractor::class)],
            'issued_to_name' => ['nullable', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function detail(MaterialIssue $issue): MaterialIssue
    {
        return $issue->load([
            'warehouse:id,code,name,type,project_id',
            'issuedToUser:id,name',
            'subcontractor:id,name',
            'approver:id,name',
            'items.material:id,code,name,item_type',
            'items.unit:id,symbol',
            'items.task:id,wbs_code,name',
            'attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ])->loadCount('items');
    }
}

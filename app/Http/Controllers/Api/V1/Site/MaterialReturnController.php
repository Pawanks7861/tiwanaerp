<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialReturnResource;
use App\Models\Inventory\MaterialReturn;
use App\Models\Projects\Project;
use App\Services\Inventory\MaterialReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialReturnController extends Controller
{
    public function __construct(private readonly MaterialReturnService $returns) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialReturn::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'type' => ['nullable', Rule::enum(MaterialReturnType::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = MaterialReturn::query()
            ->where('project_id', $project->id)
            ->with(['warehouse:id,code,name', 'vendor:id,code,name'])
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('return_type', $filters['type']))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('return_number', 'like', $term)
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)->orWhere('code', 'like', $term))
                    ->orWhereHas('items.material', fn ($m) => $m->where('name', 'like', $term)->orWhere('code', 'like', $term)));
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return MaterialReturnResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [MaterialReturn::class, $project]);
        $type = MaterialReturnType::from($request->validate([
            'return_type' => ['required', Rule::enum(MaterialReturnType::class)],
        ])['return_type']);
        $return = $this->returns->create($project, $type, $this->validated($request, $type));

        return (new MaterialReturnResource($this->detail($return)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, MaterialReturn $materialReturn): MaterialReturnResource
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);
        Gate::authorize('view', $materialReturn);

        return new MaterialReturnResource($this->detail($materialReturn));
    }

    public function update(Request $request, Project $project, MaterialReturn $materialReturn): MaterialReturnResource
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);
        Gate::authorize('update', $materialReturn);
        $return = $this->returns->update($materialReturn, $this->validated($request, $materialReturn->return_type));

        return new MaterialReturnResource($this->detail($return));
    }

    public function submit(Request $request, Project $project, MaterialReturn $materialReturn): MaterialReturnResource
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $materialReturn);
        $this->returns->submit($materialReturn, $request->user());

        return new MaterialReturnResource($this->detail($materialReturn->fresh()));
    }

    public function cancel(Request $request, Project $project, MaterialReturn $materialReturn): MaterialReturnResource
    {
        abort_unless((int) $materialReturn->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $materialReturn);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->returns->cancel($materialReturn, $request->user(), $reason);

        return new MaterialReturnResource($this->detail($materialReturn->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, MaterialReturnType $type): array
    {
        $vendor = $type === MaterialReturnType::ToVendor;

        return $request->validate([
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['required', 'integer'],
            'vendor_id' => [$vendor ? 'required_without:grn_id' : 'prohibited', 'nullable', 'integer'],
            'grn_id' => [$vendor ? 'nullable' : 'prohibited', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_issue_item_id' => [$vendor ? 'prohibited' : 'required', 'nullable', 'integer'],
            'items.*.grn_item_id' => [$vendor ? 'nullable' : 'prohibited', 'nullable', 'integer'],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function detail(MaterialReturn $return): MaterialReturn
    {
        return $return->load([
            'warehouse:id,code,name,type,project_id',
            'vendor:id,code,name',
            'grn:id,grn_number',
            'approver:id,name',
            'items.material:id,code,name,item_type',
            'items.unit:id,symbol',
            'attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ])->loadCount('items');
    }
}

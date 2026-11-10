<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Inventory\AdjustmentReason;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\Inventory\StockAdjustment;
use App\Models\Projects\Project;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockAdjustmentController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [StockAdjustment::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'reason' => ['nullable', Rule::enum(AdjustmentReason::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = StockAdjustment::query()
            ->where('project_id', $project->id)
            ->with('warehouse:id,code,name')
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['reason']), fn ($q) => $q->where('reason', $filters['reason']))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('adjustment_number', 'like', $term)
                    ->orWhere('remarks', 'like', $term));
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return StockAdjustmentResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [StockAdjustment::class, $project]);
        $adjustment = $this->adjustments->create($project, $this->validated($request));

        return (new StockAdjustmentResource($this->detail($adjustment)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('view', $stockAdjustment);

        return new StockAdjustmentResource($this->detail($stockAdjustment));
    }

    public function update(Request $request, Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('update', $stockAdjustment);
        $adjustment = $this->adjustments->update($stockAdjustment, $this->validated($request));

        return new StockAdjustmentResource($this->detail($adjustment));
    }

    public function submit(Request $request, Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $stockAdjustment);
        $this->adjustments->submit($stockAdjustment, $request->user());

        return new StockAdjustmentResource($this->detail($stockAdjustment->fresh()));
    }

    public function approve(Request $request, Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        if ($stockAdjustment->status !== InventoryDocumentStatus::Approved) {
            Gate::authorize('approve', $stockAdjustment);
        } else {
            abort_unless($request->user()->can('inventory.approve_adjustment') && $request->user()->can('view', $stockAdjustment), 403);
        }
        $this->adjustments->approve($stockAdjustment, $request->user());

        return new StockAdjustmentResource($this->detail($stockAdjustment->fresh()));
    }

    public function reject(Request $request, Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('reject', $stockAdjustment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->adjustments->reject($stockAdjustment, $request->user(), $reason);

        return new StockAdjustmentResource($this->detail($stockAdjustment->fresh()));
    }

    public function cancel(Request $request, Project $project, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        abort_unless((int) $stockAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $stockAdjustment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->adjustments->cancel($stockAdjustment, $request->user(), $reason);

        return new StockAdjustmentResource($this->detail($stockAdjustment->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.physical_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function detail(StockAdjustment $adjustment): StockAdjustment
    {
        return $adjustment->load([
            'warehouse:id,code,name,type,project_id',
            'approver:id,name',
            'submitter:id,name',
            'items.material:id,code,name,item_type',
            'items.unit:id,symbol',
            'attachments.uploader:id,name',
        ])->loadCount('items');
    }
}

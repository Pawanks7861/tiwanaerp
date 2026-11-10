<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Subcontract\MilestoneStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkOrderResource;
use App\Models\Projects\Project;
use App\Models\Subcontract\WorkOrder;
use App\Rules\ExistsInCompany;
use App\Models\Masters\Subcontractor;
use App\Services\Subcontract\SubcontractorBillService;
use App\Services\Subcontract\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkOrderApiController extends Controller
{
    public function __construct(
        private readonly WorkOrderService $orders,
        private readonly SubcontractorBillService $bills,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [WorkOrder::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(WorkOrderStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = WorkOrder::query()
            ->where('project_id', $project->id)
            ->with('subcontractor:id,code,name')
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('wo_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('subcontractor', fn ($s) => $s->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return WorkOrderResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [WorkOrder::class, $project]);
        $order = $this->orders->create($project, $this->validated($request));

        return (new WorkOrderResource($this->detail($order)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('view', $workOrder);

        return new WorkOrderResource($this->detail($workOrder));
    }

    public function update(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('update', $workOrder);
        $order = $this->orders->update($workOrder, $this->validated($request));

        return new WorkOrderResource($this->detail($order));
    }

    public function destroy(Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $workOrder);
        $this->orders->delete($workOrder);

        return response()->json(['message' => 'Work order deleted.']);
    }

    public function submit(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $workOrder);
        $this->orders->submit($workOrder, $request->user());

        return new WorkOrderResource($this->detail($workOrder->fresh()));
    }

    public function complete(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('complete', $workOrder);
        $this->orders->complete($workOrder, $request->user());

        return new WorkOrderResource($this->detail($workOrder->fresh()));
    }

    public function close(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('close', $workOrder);
        $this->orders->close($workOrder, $request->user());

        return new WorkOrderResource($this->detail($workOrder->fresh()));
    }

    public function cancel(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $workOrder);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->orders->cancel($workOrder, $request->user(), $reason);

        return new WorkOrderResource($this->detail($workOrder->fresh()));
    }

    public function updateMilestones(Request $request, Project $project, WorkOrder $workOrder): WorkOrderResource
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('updateMilestones', $workOrder);

        $rows = $request->validate([
            'milestones' => ['required', 'array', 'max:100'],
            'milestones.*.id' => ['required', 'integer'],
            'milestones.*.status' => ['required', Rule::enum(MilestoneStatus::class)],
        ])['milestones'];

        $this->orders->updateMilestones($workOrder, $rows);

        return new WorkOrderResource($this->detail($workOrder->fresh()));
    }

    public function billableLines(Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('view', $workOrder);

        return response()->json([
            'data' => $this->bills->billableLines($workOrder),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'subcontractor_id' => ['required', 'integer', new ExistsInCompany(Subcontractor::class)],
            'wo_date' => ['required', 'date'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'retention_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'advance_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'tax_rate_id' => ['nullable', 'integer'],
            'tds_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'milestones' => ['nullable', 'array', 'max:50'],
            'milestones.*.name' => ['required', 'string', 'max:200'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.amount_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ], [], [
            'subcontractor_id' => 'subcontractor',
            'items.*.quantity' => 'quantity',
            'items.*.rate' => 'rate',
        ]);
    }

    private function detail(WorkOrder $order): WorkOrder
    {
        return $order->load([
            'subcontractor:id,code,name,gstin,mobile',
            'items.unit:id,symbol',
            'items.boqItem:id,item_code,name',
            'items.task:id,wbs_code,name',
            'milestones',
            'latestApprovalRequest',
            'attachments',
        ])->loadCount('items');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubcontractorBillResource;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Services\Subcontract\SubcontractorBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubcontractorBillApiController extends Controller
{
    public function __construct(private readonly SubcontractorBillService $bills) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [SubcontractorBill::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SubcontractorBillStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = SubcontractorBill::query()
            ->where('project_id', $project->id)
            ->with(['subcontractor:id,code,name', 'workOrder:id,wo_number'])
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('bill_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('subcontractor_invoice_no', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return SubcontractorBillResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [SubcontractorBill::class, $project]);
        $bill = $this->bills->create($project, $this->validated($request, true));

        return (new SubcontractorBillResource($this->detail($bill)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, SubcontractorBill $subcontractorBill): SubcontractorBillResource
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('view', $subcontractorBill);

        return new SubcontractorBillResource($this->detail($subcontractorBill));
    }

    public function update(Request $request, Project $project, SubcontractorBill $subcontractorBill): SubcontractorBillResource
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('update', $subcontractorBill);
        $bill = $this->bills->update($subcontractorBill, $this->validated($request, false));

        return new SubcontractorBillResource($this->detail($bill));
    }

    public function destroy(Project $project, SubcontractorBill $subcontractorBill): JsonResponse
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $subcontractorBill);
        $this->bills->delete($subcontractorBill);

        return response()->json(['message' => 'Bill deleted.']);
    }

    public function submit(Request $request, Project $project, SubcontractorBill $subcontractorBill): SubcontractorBillResource
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $subcontractorBill);
        $this->bills->submit($subcontractorBill, $request->user());

        return new SubcontractorBillResource($this->detail($subcontractorBill->fresh()));
    }

    public function adjust(Request $request, Project $project, SubcontractorBill $subcontractorBill): SubcontractorBillResource
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('certify', $subcontractorBill);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.id' => ['required', 'integer'],
            'items.*.certified_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ]);

        $this->bills->adjust($subcontractorBill, $data);

        return new SubcontractorBillResource($this->detail($subcontractorBill->fresh()));
    }

    public function reverse(Request $request, Project $project, SubcontractorBill $subcontractorBill): SubcontractorBillResource
    {
        abort_unless((int) $subcontractorBill->project_id === (int) $project->id, 404);
        Gate::authorize('reverse', $subcontractorBill);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->bills->reverse($subcontractorBill, $request->user(), $reason);

        return new SubcontractorBillResource($this->detail($subcontractorBill->fresh()));
    }

    public function prefill(Project $project, WorkOrder $workOrder): JsonResponse
    {
        abort_unless((int) $workOrder->project_id === (int) $project->id, 404);
        Gate::authorize('create', [SubcontractorBill::class, $project]);

        return response()->json([
            'data' => [
                'work_order_id' => $workOrder->id,
                'wo_number' => $workOrder->wo_number,
                'retention_percent' => $workOrder->retention_percent,
                'tds_percent' => $workOrder->tds_percent,
                'tax_percent' => $workOrder->tax_percent,
                'advance_balance' => $this->bills->advanceBalance($workOrder)->toMoney(),
                'lines' => $this->bills->billableLines($workOrder),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? ['work_order_id' => ['required', 'integer']] : []),
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'subcontractor_invoice_no' => ['nullable', 'string', 'max:60'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.work_order_item_id' => ['required', 'integer'],
            'items.*.claimed_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.certified_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
        ], [], ['work_order_id' => 'work order', 'items.*.claimed_qty' => 'claimed quantity']);
    }

    private function detail(SubcontractorBill $bill): SubcontractorBill
    {
        return $bill->load([
            'subcontractor:id,code,name,gstin',
            'workOrder:id,wo_number',
            'items.workOrderItem:id,description',
            'latestApprovalRequest',
            'attachments',
        ]);
    }
}

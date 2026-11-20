<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\CostHead;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Finance\VendorBillType;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\DeniesRetiredFinanceWrites;
use App\Http\Resources\VendorBillResource;
use App\Models\Finance\VendorBill;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Services\Finance\VendorBillService;
use App\Services\Finance\VendorInvoiceMatchingService;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VendorBillApiController extends Controller
{
    use DeniesRetiredFinanceWrites;

    public function __construct(
        private readonly VendorBillService $bills,
        private readonly VendorInvoiceMatchingService $matching,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [VendorBill::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VendorBillStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $page = $project->vendorBills()
            ->with(['vendor:id,code,name', 'purchaseOrder:id,po_number'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('bill_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('vendor_invoice_no', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return VendorBillResource::collection($page);
    }

    public function eligiblePurchaseOrders(Project $project): JsonResponse
    {
        Gate::authorize('create', [VendorBill::class, $project]);

        $orders = $project->purchaseOrders()->with('vendor:id,name')
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed])
            ->whereHas('grns', fn ($q) => $q->where('status', 'approved'))
            ->orderByDesc('id')->get(['id', 'po_number', 'vendor_id']);

        return response()->json([
            'data' => $orders->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'po_number' => $o->po_number,
                'vendor' => $o->vendor?->only(['id', 'name']),
            ])->values(),
        ]);
    }

    public function prefill(Project $project, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('create', [VendorBill::class, $project]);

        return response()->json([
            'data' => [
                'purchase_order' => $purchaseOrder->only(['id', 'po_number']),
                'vendor' => $purchaseOrder->vendor?->only(['id', 'name', 'gstin']),
                'tax_type' => $purchaseOrder->tax_type->value,
                'lines' => $this->bills->billableGrnLines($purchaseOrder),
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        Gate::authorize('create', [VendorBill::class, $project]);
        $bill = $this->bills->create($project, $this->validated($request, true));

        return (new VendorBillResource($this->detail($bill)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, VendorBill $vendorBill): VendorBillResource
    {
        $this->assertOnProject($project, $vendorBill);
        Gate::authorize('view', $vendorBill);

        $bill = $this->detail($vendorBill);
        $bill->offsetSet('matching', $this->matching->analyze($bill));

        return new VendorBillResource($bill);
    }

    public function update(Request $request, Project $project, VendorBill $vendorBill): VendorBillResource
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $vendorBill);
        Gate::authorize('update', $vendorBill);
        $bill = $this->bills->update($vendorBill, $this->validated($request, false, $vendorBill->bill_type));

        return new VendorBillResource($this->detail($bill));
    }

    public function destroy(Project $project, VendorBill $vendorBill): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $vendorBill);
        Gate::authorize('delete', $vendorBill);
        $this->bills->delete($vendorBill);

        return response()->json(['message' => 'Vendor bill deleted.']);
    }

    public function submit(Request $request, Project $project, VendorBill $vendorBill): VendorBillResource
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $vendorBill);
        Gate::authorize('submit', $vendorBill);
        $this->bills->submit($vendorBill, $request->user());

        return new VendorBillResource($this->detail($vendorBill->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating, ?VendorBillType $type = null): array
    {
        $type ??= VendorBillType::tryFrom((string) $request->input('bill_type'));
        $direct = $type === VendorBillType::Direct;

        return $request->validate([
            ...($creating ? ['bill_type' => ['required', Rule::enum(VendorBillType::class)]] : []),
            'vendor_invoice_no' => ['required', 'string', 'max:60'],
            'vendor_invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:vendor_invoice_date'],
            'tds_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            ...($direct ? [
                'vendor_id' => ['required', 'integer'],
                'cost_head' => ['required', Rule::enum(CostHead::class)],
                'task_id' => ['nullable', 'integer'],
                'boq_item_id' => ['nullable', 'integer'],
                'items.*.description' => ['required', 'string', 'max:255'],
                'items.*.hsn_sac' => ['nullable', 'string', 'max:10'],
                'items.*.unit_id' => ['required', 'integer'],
                'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
                'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
                'items.*.discount_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'items.*.tax_rate_id' => ['nullable', 'integer'],
            ] : [
                'purchase_order_id' => [$creating ? 'required' : 'nullable', 'integer'],
                'items.*.grn_item_id' => ['required', 'integer'],
                'items.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            ]),
        ], [], ['purchase_order_id' => 'purchase order', 'vendor_id' => 'vendor', 'cost_head' => 'cost head']);
    }

    private function detail(VendorBill $bill): VendorBill
    {
        $bill->load([
            'vendor:id,code,name,gstin',
            'purchaseOrder:id,po_number',
            'items.unit:id,symbol',
            'items.grnItem:id,grn_id,accepted_qty',
            'items.purchaseOrderItem:id,quantity',
            'latestApprovalRequest.submitter:id,name',
            'attachments.uploader:id,name',
        ]);

        if ($bill->purchase_order_id && $bill->purchaseOrder) {
            $lines = collect($this->bills->billableGrnLines($bill->purchaseOrder, $bill))->keyBy('grn_item_id');
            foreach ($bill->items as $item) {
                if ($item->grn_item_id && $lines->has($item->grn_item_id)) {
                    $row = $lines[$item->grn_item_id];
                    $item->forceFill([
                        'previous_billed_qty' => $row['billed_qty'],
                        'available_qty' => Decimal::of($row['balance_qty'])->plus($item->quantity)->toQuantity(),
                    ]);
                }
            }
        }

        return $bill;
    }

    private function assertOnProject(Project $project, VendorBill $bill): void
    {
        abort_unless((int) $bill->project_id === (int) $project->id, 404);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Site\StoreGrnRequest;
use App\Http\Resources\GrnResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Services\Attachments\AttachmentService;
use App\Services\Procurement\GrnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class GRNController extends Controller
{
    public function __construct(
        private readonly GrnService $grns,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Grn::class, $project]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'vendor_id' => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 25);

        $rows = Grn::query()
            ->where('project_id', $project->id)
            ->with(['vendor:id,code,name', 'purchaseOrder:id,po_number', 'warehouse:id,code,name'])
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($term) {
                    $w->where('grn_number', 'like', $term)
                        ->orWhere('vendor_challan_no', 'like', $term)
                        ->orWhere('vehicle_no', 'like', $term)
                        ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'like', $term))
                        ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['vendor_id']), fn ($q) => $q->where('vendor_id', $filters['vendor_id']))
            ->when(! empty($filters['purchase_order_id']), fn ($q) => $q->where('purchase_order_id', $filters['purchase_order_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('receipt_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('receipt_date', '<=', $filters['to']))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return GrnResource::collection($rows);
    }

    public function pendingPOs(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Grn::class, $project]);

        $orders = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived])
            ->with([
                'vendor:id,code,name',
                'items.material:id,code,name',
                'items.unit:id,symbol',
            ])
            ->orderByDesc('id')
            ->get();

        return PurchaseOrderResource::collection($orders);
    }

    public function eligiblePurchaseOrders(Project $project): JsonResponse
    {
        Gate::authorize('create', [Grn::class, $project]);

        $orders = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived])
            ->with(['vendor:id,code,name', 'items.material:id,code,name', 'items.unit:id,symbol'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $data = $orders->map(fn (PurchaseOrder $po) => [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
            'vendor' => $po->vendor?->only(['id', 'code', 'name']),
            'items' => $this->grns->receivableLines($po),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreGrnRequest $request, Project $project): JsonResponse
    {
        $data = $request->payload();
        $existing = Grn::query()
            ->where('project_id', $project->id)
            ->where('uuid', $data['uuid'])
            ->first();

        if ($existing !== null) {
            return (new GrnResource($this->detail($existing)))->response();
        }

        $po = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->findOrFail($data['purchase_order_id']);

        $data['warehouse_id'] = $data['warehouse_id']
            ?? Warehouse::query()->where('project_id', $project->id)->where('is_active', true)->value('id');

        $grn = $this->grns->create($po, $data);

        if ($request->hasFile('challan_photo')) {
            $this->attachments->store($grn, $request->file('challan_photo'), 'challan');
        }

        return (new GrnResource($this->detail($grn->fresh())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Grn $grn): GrnResource
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);
        Gate::authorize('view', $grn);

        return new GrnResource($this->detail($grn));
    }

    public function update(StoreGrnRequest $request, Project $project, Grn $grn): GrnResource
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);
        Gate::authorize('update', $grn);

        $data = $request->payloadForUpdate();
        $grn = $this->grns->update($grn, $data);

        if ($request->hasFile('challan_photo')) {
            $this->attachments->store($grn, $request->file('challan_photo'), 'challan');
        }

        return new GrnResource($this->detail($grn->fresh()));
    }

    public function submit(Request $request, Project $project, Grn $grn): GrnResource
    {
        abort_unless((int) $grn->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $grn);
        $this->grns->submit($grn, $request->user());

        return new GrnResource($this->detail($grn->fresh()));
    }

    private function detail(Grn $grn): Grn
    {
        return $grn->load([
            'purchaseOrder:id,po_number,status,vendor_id',
            'vendor:id,code,name,gstin',
            'warehouse:id,code,name,type,project_id',
            'items.material:id,code,name',
            'items.unit:id,symbol',
            'attachments.uploader:id,name',
            'weighbridgeReceipt.attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\PurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Core\Company;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Services\Procurement\ProcurementQuantityService;
use App\Services\Procurement\PurchaseOrderAmendmentService;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Math\Decimal;
use App\Support\Pdf\PdfResponder;
use App\Support\Pdf\PurchaseOrderPdfDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly PurchaseOrderAmendmentService $amendments,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [PurchaseOrder::class, $project]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'vendor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 25);

        $rows = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->with(['vendor:id,code,name'])
            ->withCount('items')
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($term) {
                    $w->where('po_number', 'like', $term)
                        ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term)->orWhere('code', 'like', $term))
                        ->orWhereHas('items.materialRequestItem.materialRequest', fn ($m) => $m->where('request_number', 'like', $term));
                });
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['vendor_id']), fn ($q) => $q->where('vendor_id', $filters['vendor_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('po_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('po_date', '<=', $filters['to']))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return PurchaseOrderResource::collection($rows);
    }

    public function eligibleMaterialRequests(Project $project): JsonResponse
    {
        Gate::authorize('create', [PurchaseOrder::class, $project]);

        $lines = MaterialRequestItem::query()
            ->whereHas('materialRequest', fn (Builder $m) => $m->where('project_id', $project->id)->whereIn('status', ['approved', 'partially_ordered']))
            ->with(['materialRequest:id,request_number,status', 'material:id,code,name,tax_rate_id,hsn_sac', 'unit:id,symbol'])
            ->orderBy('material_request_id')->orderBy('sort_order')
            ->limit(1000)->get();
        $remaining = $this->quantities->remaining($lines);

        $data = $lines->map(fn (MaterialRequestItem $i) => [
            'id' => $i->id,
            'material_request_id' => $i->material_request_id,
            'request_number' => $i->materialRequest?->request_number,
            'material' => $i->material?->only(['id', 'code', 'name']),
            'description' => $i->material?->name,
            'hsn_sac' => $i->material?->hsn_sac,
            'tax_rate_id' => $i->material?->tax_rate_id,
            'unit' => $i->unit?->only(['id', 'symbol']),
            'requested_qty' => $i->quantity,
            'ordered_qty' => $i->ordered_qty,
            'remaining_qty' => $remaining[$i->id] ?? '0',
        ])->filter(fn ($l) => Decimal::of($l['remaining_qty'])->isPositive())->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(PurchaseOrderRequest $request, Project $project): JsonResponse
    {
        $po = $this->orders->createDirect($project, $request->validated());

        return (new PurchaseOrderResource($this->detail($po)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($this->detail($purchaseOrder));
    }

    public function update(PurchaseOrderRequest $request, Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        $po = $this->orders->update($purchaseOrder, $request->validated());

        return new PurchaseOrderResource($this->detail($po));
    }

    public function submit(Request $request, Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $purchaseOrder);
        $this->orders->submit($purchaseOrder, $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder->fresh()));
    }

    public function amend(Request $request, Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('amend', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->amendments->amend($purchaseOrder, $data['reason'], $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder->fresh()));
    }

    public function cancel(Request $request, Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->orders->cancel($purchaseOrder, $data['reason'], $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder->fresh()));
    }

    public function close(Request $request, Project $project, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('close', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->orders->close($purchaseOrder, $data['reason'], $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder->fresh()));
    }

    public function pdf(Request $request, Project $project, PurchaseOrder $purchaseOrder): Response
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        Gate::authorize('export', $purchaseOrder);

        $company = Company::query()->findOrFail($purchaseOrder->company_id);
        $data = app(PurchaseOrderPdfDataBuilder::class)->build($purchaseOrder, $project, $company);
        $pdf = Pdf::loadView('pdf.purchase-order', $data)->setPaper('a4');

        $name = $purchaseOrder->po_number.($purchaseOrder->revision_no > 0 ? '-R'.$purchaseOrder->revision_no : '').'.pdf';

        return PdfResponder::send($pdf, $name, $request->routeIs('api.v1.projects.purchase-orders.pdf.view'));
    }

    private function detail(PurchaseOrder $po): PurchaseOrder
    {
        return $po->load([
            'vendor:id,code,name,gstin,state_code,city,payment_terms',
            'approver:id,name',
            'items.material:id,code,name',
            'items.unit:id,symbol',
            'items.taxRate:id,name,rate',
            'items.materialRequestItem.materialRequest:id,request_number',
            'revisions' => fn ($q) => $q->latest('id')->limit(20),
            'attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ])->loadCount('items');
    }
}

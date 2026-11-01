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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class GRNController extends Controller
{
    public function __construct(
        private readonly GrnService $grns,
        private readonly AttachmentService $attachments,
    ) {}

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

    public function store(StoreGrnRequest $request, Project $project): JsonResponse
    {
        $data = $request->payload();
        $existing = Grn::query()
            ->where('project_id', $project->id)
            ->where('uuid', $data['uuid'])
            ->first();

        if ($existing !== null) {
            return (new GrnResource($this->withLines($existing)))->response();
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

        return (new GrnResource($this->withLines($grn->fresh())))
            ->response()
            ->setStatusCode(201);
    }

    private function withLines(Grn $grn): Grn
    {
        return $grn->load([
            'purchaseOrder:id,po_number',
            'vendor:id,code,name',
            'warehouse:id,code,name',
            'items.material:id,code,name',
            'items.unit:id,symbol',
            'attachments',
        ]);
    }
}

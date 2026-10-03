<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\RequestPriority;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Procurement\MaterialRequestRequest;
use App\Models\Boq\BoqItem;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Procurement\MaterialRequestService;
use App\Services\Procurement\ProcurementQuantityService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialRequestController extends Controller
{
    public function __construct(
        private readonly MaterialRequestService $requests,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [MaterialRequest::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MaterialRequestStatus::class)],
            'priority' => ['nullable', Rule::enum(RequestPriority::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->materialRequests()
            ->with(['site:id,name', 'requester:id,name', 'items:id,material_request_id,quantity,ordered_qty,received_qty'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('request_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $page->getCollection()->each->setRelation('project', $project);

        return Inertia::render('Procurement/MaterialRequests/Index', [
            'project' => ProjectHeader::for($project),
            'requests' => $page->through(fn (MaterialRequest $mr) => [
                ...$this->header($mr),
                'items_count' => $mr->items_count,
                'ordered_percent' => $this->progress($mr, 'ordered_qty'),
                'received_percent' => $this->progress($mr, 'received_qty'),
                'can' => $this->abilities($user, $mr),
            ]),
            'filters' => $filters,
            'statuses' => MaterialRequestStatus::options(),
            'priorities' => RequestPriority::options(),
            'can' => ['create' => $user->can('create', [MaterialRequest::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [MaterialRequest::class, $project]);

        return Inertia::render('Procurement/MaterialRequests/Form', [
            'project' => ProjectHeader::for($project),
            'materialRequest' => null,
            ...$this->formOptions($project),
        ]);
    }

    public function store(MaterialRequestRequest $request, Project $project): RedirectResponse
    {
        $mr = $this->requests->create($project, $request->validated(), $request->user());

        return redirect()->route('projects.material-requests.show', [$project, $mr])->with('success', "Material request {$mr->request_number} saved as draft.");
    }

    public function show(Request $request, Project $project, MaterialRequest $materialRequest): Response
    {
        Gate::authorize('view', $materialRequest);

        $user = $request->user();
        $mr = $materialRequest->load(['site:id,name', 'requester:id,name', 'approver:id,name']);
        $items = $mr->items()->with(['material:id,code,name', 'unit:id,symbol', 'boqItem:id,item_code,name', 'task:id,wbs_code,name'])->get();
        $remaining = $mr->status->isProcurable() ? $this->quantities->remaining($items) : [];
        $seePurchase = $user->can('purchase.view') || $user->can('rfq.view');

        return Inertia::render('Procurement/MaterialRequests/Show', [
            'project' => ProjectHeader::for($project),
            'materialRequest' => [
                ...$this->header($mr),
                'remarks' => $mr->remarks,
                'cancelled_reason' => $mr->cancelled_reason,
                'approved_by' => $mr->approver?->name,
                'approved_at' => $mr->approved_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (MaterialRequestItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'boq_item' => $i->boqItem ? trim($i->boqItem->item_code.' '.$i->boqItem->name) : null,
                'task' => $i->task ? trim($i->task->wbs_code.' '.$i->task->name) : null,
                'quantity' => $i->quantity,
                'ordered_qty' => $i->ordered_qty,
                'received_qty' => $i->received_qty,
                'remaining_qty' => $remaining[$i->id] ?? null,
                'remarks' => $i->remarks,
            ])->all(),
            'linked' => $seePurchase ? $this->linkedDocuments($items->pluck('id')->all()) : null,
            'approval' => ProcurementPresenter::approval($mr, $user),
            'attachments' => ProcurementPresenter::attachments($mr),
            'can' => [
                ...$this->abilities($user, $mr),
                'create_rfq' => $mr->status->isProcurable() && $user->can('create', [Rfq::class, $project]),
                'attach' => $user->can('update', $mr),
            ],
        ]);
    }

    public function edit(Project $project, MaterialRequest $materialRequest): Response
    {
        Gate::authorize('update', $materialRequest);

        $items = $materialRequest->items()->get();

        return Inertia::render('Procurement/MaterialRequests/Form', [
            'project' => ProjectHeader::for($project),
            'materialRequest' => [
                ...$this->header($materialRequest),
                'site_id' => $materialRequest->site_id,
                'remarks' => $materialRequest->remarks,
                'items' => $items->map(fn (MaterialRequestItem $i) => $i->only(['id', 'material_id', 'boq_item_id', 'task_id', 'unit_id', 'quantity', 'remarks']))->all(),
            ],
            ...$this->formOptions($project, $items->pluck('boq_item_id')->filter()->all()),
        ]);
    }

    public function update(MaterialRequestRequest $request, Project $project, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->requests->update($materialRequest, $request->validated());

        return redirect()->route('projects.material-requests.show', [$project, $materialRequest])->with('success', 'Material request updated.');
    }

    public function destroy(Project $project, MaterialRequest $materialRequest): RedirectResponse
    {
        Gate::authorize('delete', $materialRequest);

        $this->requests->delete($materialRequest);

        return redirect()->route('projects.material-requests.index', $project)->with('success', "Material request {$materialRequest->request_number} deleted.");
    }

    public function submit(Request $request, Project $project, MaterialRequest $materialRequest): RedirectResponse
    {
        Gate::authorize('submit', $materialRequest);

        $this->requests->submit($materialRequest, $request->user());

        return back()->with('success', 'Material request submitted for approval.');
    }

    public function cancel(Request $request, Project $project, MaterialRequest $materialRequest): RedirectResponse
    {
        Gate::authorize('cancel', $materialRequest);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->requests->cancel($materialRequest, $reason);

        return back()->with('success', 'Material request cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function header(MaterialRequest $mr): array
    {
        return [
            'id' => $mr->id,
            'request_number' => $mr->request_number,
            'request_date' => $mr->request_date?->toDateString(),
            'required_date' => $mr->required_date?->toDateString(),
            'priority' => $mr->priority->value,
            'priority_label' => $mr->priority->label(),
            'status' => $mr->status->value,
            'status_label' => $mr->status->label(),
            'site' => $mr->relationLoaded('site') ? $mr->site?->name : null,
            'requested_by' => $mr->relationLoaded('requester') ? $mr->requester?->name : null,
        ];
    }

    /**
     * Average line progress (each line capped at 100 %), as a whole percent string.
     */
    private function progress(MaterialRequest $mr, string $field): string
    {
        if ($mr->items->isEmpty()) {
            return '0';
        }

        $sum = $mr->items->reduce(function (Decimal $carry, MaterialRequestItem $i) use ($field) {
            $qty = Decimal::of($i->quantity);
            if ($qty->isZero()) {
                return $carry;
            }
            $ratio = Decimal::of($i->{$field})->dividedBy($qty);

            return $carry->plus($ratio->greaterThan('1') ? '1' : $ratio);
        }, Decimal::zero());

        return $sum->times(100)->dividedBy($mr->items->count())->round(0)->toString();
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, MaterialRequest $mr): array
    {
        $editable = $mr->isEditable();

        return [
            'update' => $editable && $user->can('update', $mr),
            'delete' => $editable && $user->can('delete', $mr),
            'submit' => $editable && $user->can('submit', $mr),
            'cancel' => $mr->status->isProcurable() && $user->can('cancel', $mr),
        ];
    }

    /**
     * RFQs and purchase orders that reference these MR lines (for traceability).
     *
     * @param  list<int>  $itemIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function linkedDocuments(array $itemIds): array
    {
        $rfqs = RfqItem::query()->whereIn('material_request_item_id', $itemIds)->whereHas('rfq')
            ->with('rfq:id,project_id,rfq_number,status')->get()->pluck('rfq')->unique('id')->values();
        $orders = PurchaseOrderItem::query()->whereIn('material_request_item_id', $itemIds)->whereHas('purchaseOrder')
            ->with('purchaseOrder:id,project_id,po_number,status,revision_no')->get()->pluck('purchaseOrder')->unique('id')->values();

        return [
            'rfqs' => $rfqs->map(fn ($r) => ['id' => $r->id, 'number' => $r->rfq_number, 'status' => $r->status->value, 'status_label' => $r->status->label(),
                'url' => route('projects.rfqs.show', [$r->project_id, $r->id])])->all(),
            'purchase_orders' => $orders->map(fn ($p) => ['id' => $p->id, 'number' => $p->po_number, 'status' => $p->status->value, 'status_label' => $p->status->label(),
                'url' => route('projects.purchase-orders.show', [$p->project_id, $p->id])])->all(),
        ];
    }

    /**
     * @param  list<int>  $keepBoqItemIds  BOQ lines already linked (may belong to an older revision)
     * @return array<string, mixed>
     */
    private function formOptions(Project $project, array $keepBoqItemIds = []): array
    {
        $boqItems = ProcurementPresenter::boqItemOptions($project);
        $missing = array_diff($keepBoqItemIds, array_column($boqItems, 'value'));
        if ($missing !== []) {
            foreach (BoqItem::query()->whereKey($missing)->get(['id', 'item_code', 'name', 'unit_id']) as $old) {
                $boqItems[] = ['value' => $old->id, 'label' => trim($old->item_code.' '.$old->name), 'description' => 'Earlier BOQ revision', 'unit_id' => $old->unit_id];
            }
        }

        return [
            'materials' => ProcurementPresenter::materialOptions(),
            'units' => ProcurementPresenter::unitOptions(),
            'sites' => ProcurementPresenter::siteOptions($project),
            'boqItems' => $boqItems,
            'tasks' => ProcurementPresenter::taskOptions($project),
            'priorities' => RequestPriority::options(),
            'today' => now()->toDateString(),
        ];
    }
}

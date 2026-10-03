<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\IndianState;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Procurement\PurchaseOrderRequest;
use App\Models\Core\Company;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\PurchaseOrderRevision;
use App\Models\Procurement\Rfq;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Procurement\ProcurementQuantityService;
use App\Services\Procurement\PurchaseOrderAmendmentService;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Format\IndianNumber;
use App\Support\Math\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly PurchaseOrderAmendmentService $amendments,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [PurchaseOrder::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->purchaseOrders()
            ->with('vendor:id,code,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('po_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Procurement/PurchaseOrders/Index', [
            'project' => ProjectHeader::for($project),
            'orders' => $page->through(fn (PurchaseOrder $po) => [
                ...$this->header($po),
                'vendor' => $po->vendor?->only(['id', 'code', 'name']),
                'items_count' => $po->items_count,
            ]),
            'filters' => $filters,
            'statuses' => PurchaseOrderStatus::options(),
            'can' => ['create' => $user->can('create', [PurchaseOrder::class, $project])],
        ]);
    }

    /** Direct PO form (no RFQ). */
    public function create(Project $project): Response
    {
        Gate::authorize('create', [PurchaseOrder::class, $project]);

        return Inertia::render('Procurement/PurchaseOrders/Form', [
            'project' => ProjectHeader::for($project),
            'order' => null,
            ...$this->formOptions($project, null),
        ]);
    }

    public function store(PurchaseOrderRequest $request, Project $project): RedirectResponse
    {
        $po = $this->orders->createDirect($project, $request->validated());

        return redirect()->route('projects.purchase-orders.show', [$project, $po])->with('success', "Purchase order {$po->po_number} saved as draft.");
    }

    public function storeFromRfq(Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('view', $rfq);
        Gate::authorize('create', [PurchaseOrder::class, $project]);

        $po = $this->orders->createFromComparison($rfq);

        return redirect()->route('projects.purchase-orders.show', [$project, $po])->with('success', "Purchase order {$po->po_number} created from the approved comparison.");
    }

    public function show(Request $request, Project $project, PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        $user = $request->user();
        $po = $purchaseOrder->load(['vendor', 'approver:id,name', 'canceller:id,name', 'rfq:id,rfq_number', 'quotation:id,quotation_number']);
        $items = $po->items()->with(['unit:id,symbol', 'materialRequestItem.materialRequest:id,request_number', 'taxRate:id,name'])->get();

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'project' => ProjectHeader::for($project),
            'order' => [
                ...$this->header($po),
                ...$po->only(['billing_address', 'shipping_address', 'place_of_supply_state', 'vendor_state_code', 'payment_terms', 'terms', 'remarks', 'direct_justification', 'cancelled_reason', ...PurchaseOrder::MONEY_FIELDS]),
                'place_of_supply_label' => IndianState::tryFrom((string) $po->place_of_supply_state)?->label(),
                'tax_type_label' => $po->tax_type?->label(),
                'vendor' => ProcurementPresenter::vendor($po->vendor),
                'rfq' => $po->rfq ? ['id' => $po->rfq->id, 'number' => $po->rfq->rfq_number, 'url' => route('projects.rfqs.show', [$project, $po->rfq->id])] : null,
                'quotation_number' => $po->quotation?->quotation_number,
                'approved_by' => $po->approver?->name,
                'approved_at' => $po->approved_at?->toIso8601String(),
                'cancelled_by' => $po->canceller?->name,
                'cancelled_at' => $po->cancelled_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (PurchaseOrderItem $i) => [
                ...$i->only(['id', 'item_code', 'description', 'hsn_sac', 'quantity', 'rate', 'discount_percent', 'discount_amount', 'base_amount', 'taxable_amount',
                    'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount', 'igst_rate', 'igst_amount', 'amount', 'received_qty']),
                'unit' => $i->unit?->symbol,
                'tax_rate' => $i->taxRate?->name,
                'material_request' => $i->materialRequestItem?->materialRequest?->request_number,
            ])->all(),
            'revisions' => $po->revisions()->with('creator:id,name')->get()->map(fn (PurchaseOrderRevision $r) => [
                'id' => $r->id,
                'revision_no' => $r->revision_no,
                'reason' => $r->reason,
                'created_by' => $r->creator?->name,
                'created_at' => $r->created_at?->toIso8601String(),
                'grand_total' => $r->snapshot['header']['grand_total'] ?? null,
            ])->all(),
            'grns' => $user->can('grn.view') ? $po->grns()->latest('id')->get(['id', 'project_id', 'grn_number', 'receipt_date', 'status'])->map(fn (Grn $g) => [
                'id' => $g->id,
                'grn_number' => $g->grn_number,
                'receipt_date' => $g->receipt_date?->toDateString(),
                'status' => $g->status->value,
                'status_label' => $g->status->label(),
                'url' => route('projects.grns.show', [$project, $g->id]),
            ])->all() : [],
            'approval' => ProcurementPresenter::approval($po, $user),
            'attachments' => ProcurementPresenter::attachments($po),
            'can' => [
                ...$this->abilities($user, $po),
                'receive' => $po->status->isReceivable() && $user->can('create', [Grn::class, $po]),
                'attach' => $user->can('update', $po),
            ],
        ]);
    }

    public function edit(Project $project, PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('update', $purchaseOrder);

        $po = $purchaseOrder;
        $items = $po->items()->with(['unit:id,symbol', 'materialRequestItem.materialRequest:id,request_number'])->get();

        return Inertia::render('Procurement/PurchaseOrders/Form', [
            'project' => ProjectHeader::for($project),
            'order' => [
                ...$this->header($po),
                ...$po->only(['billing_address', 'shipping_address', 'place_of_supply_state', 'payment_terms', 'terms', 'remarks', 'direct_justification', 'freight_amount', 'other_charges']),
                'po_date' => $po->po_date?->toDateString(),
                'delivery_date' => $po->delivery_date?->toDateString(),
                'is_direct' => $po->isDirect(),
                'vendor' => ProcurementPresenter::vendor($po->vendor()->first()),
                'items' => $items->map(fn (PurchaseOrderItem $i) => [
                    ...$i->only(['id', 'material_request_item_id', 'item_code', 'description', 'hsn_sac', 'quantity', 'rate', 'discount_percent', 'tax_rate_id', 'received_qty']),
                    'unit' => $i->unit?->symbol,
                    'material_request' => $i->materialRequestItem?->materialRequest?->request_number,
                ])->all(),
            ],
            ...$this->formOptions($project, $po),
        ]);
    }

    public function update(PurchaseOrderRequest $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->update($purchaseOrder, $request->validated());

        return redirect()->route('projects.purchase-orders.show', [$project, $purchaseOrder])->with('success', 'Purchase order updated.');
    }

    public function destroy(Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('delete', $purchaseOrder);

        $this->orders->delete($purchaseOrder);

        return redirect()->route('projects.purchase-orders.index', $project)->with('success', "Purchase order {$purchaseOrder->po_number} deleted.");
    }

    public function submit(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('submit', $purchaseOrder);

        $this->orders->submit($purchaseOrder, $request->user());

        return back()->with('success', 'Purchase order submitted for approval.');
    }

    public function amend(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('amend', $purchaseOrder);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $this->amendments->amend($purchaseOrder, $reason, $request->user());

        return redirect()->route('projects.purchase-orders.edit', [$project, $purchaseOrder])
            ->with('success', 'Amendment started. Edit the order and submit it for approval again.');
    }

    public function cancel(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('cancel', $purchaseOrder);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $this->orders->cancel($purchaseOrder, $reason, $request->user());

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function close(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('close', $purchaseOrder);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $this->orders->close($purchaseOrder, $reason, $request->user());

        return back()->with('success', 'Purchase order closed.');
    }

    /**
     * Printable PO from stored values only (no recalculation at print time).
     */
    public function pdf(Project $project, PurchaseOrder $purchaseOrder): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('export', $purchaseOrder);

        $po = $purchaseOrder->load(['vendor', 'approver:id,name']);
        $company = Company::query()->findOrFail($po->company_id);
        $items = $po->items()->with('unit:id,symbol')->get();

        $pdf = Pdf::loadView('pdf.purchase-order', [
            'po' => $po,
            'items' => $items,
            'company' => $company,
            'project' => $project,
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'words' => IndianNumber::rupeesInWords((string) $po->grand_total),
            'placeOfSupply' => IndianState::tryFrom((string) $po->place_of_supply_state)?->label(),
            'vendorState' => IndianState::tryFrom((string) $po->vendor_state_code)?->label(),
        ])->setPaper('a4');

        $name = $po->po_number.($po->revision_no > 0 ? '-R'.$po->revision_no : '').'.pdf';

        return $pdf->download($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'revision_no' => $po->revision_no,
            'po_date' => $po->po_date?->toDateString(),
            'delivery_date' => $po->delivery_date?->toDateString(),
            'tax_type' => $po->tax_type?->value,
            'grand_total' => $po->grand_total,
            'is_direct' => $po->rfq_id === null,
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, PurchaseOrder $po): array
    {
        $editable = $po->isEditable();
        $status = $po->status;
        $amendable = in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true);
        $cancellable = in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Rejected], true)
            || ($status === PurchaseOrderStatus::Draft && $po->revision_no > 0);
        $closable = in_array($status, [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], true);

        return [
            'update' => $editable && $user->can('update', $po),
            'delete' => $editable && $po->revision_no === 0 && $user->can('delete', $po),
            'submit' => $editable && $user->can('submit', $po),
            'amend' => $amendable && $user->can('amend', $po),
            'cancel' => $cancellable && $user->can('cancel', $po),
            'close' => $closable && $user->can('close', $po),
            'export' => $user->can('export', $po),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Project $project, ?PurchaseOrder $po): array
    {
        $lines = MaterialRequestItem::query()
            ->whereHas('materialRequest', fn (Builder $m) => $m->where('project_id', $project->id)->whereIn('status', ['approved', 'partially_ordered']))
            ->with(['materialRequest:id,request_number', 'material:id,code,name,tax_rate_id,hsn_sac', 'unit:id,symbol'])
            ->orderBy('material_request_id')->orderBy('sort_order')
            ->limit(1000)->get();
        $remaining = $this->quantities->remaining($lines, exceptOrderId: $po?->id);

        return [
            'lines' => $lines->map(fn (MaterialRequestItem $i) => [
                'id' => $i->id,
                'request_number' => $i->materialRequest->request_number,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'hsn_sac' => $i->material?->hsn_sac,
                'tax_rate_id' => $i->material?->tax_rate_id,
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'remaining_qty' => $remaining[$i->id],
            ])->filter(fn ($l) => Decimal::of($l['remaining_qty'])->isPositive())->values()->all(),
            'vendors' => $po === null ? ProcurementPresenter::vendorOptions() : [],
            'taxRates' => ProcurementPresenter::taxRateOptions(),
            'states' => IndianState::options(),
            'defaults' => [
                'place_of_supply_state' => $project->state_code,
                'shipping_address' => collect([$project->name, $project->address, $project->city])->filter()->implode("\n"),
                'today' => now()->toDateString(),
            ],
        ];
    }
}

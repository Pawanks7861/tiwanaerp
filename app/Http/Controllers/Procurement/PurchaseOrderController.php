<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\IndianState;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Procurement\PurchaseOrderRequest;
use App\Models\Core\Company;
use App\Models\Core\DocumentTermsTemplate;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
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
use App\Support\Math\Decimal;
use App\Support\Pdf\DocumentPdfSettings;
use App\Support\Pdf\PdfResponder;
use App\Support\Pdf\PurchaseOrderPdfDataBuilder;
use App\Support\Procurement\ProcurementSettings;
use App\Support\Tenancy\CurrentCompany;
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

    /** Purchase order from approved material request lines. RFQ is not required while that feature is off. */
    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [PurchaseOrder::class, $project]);

        $prefill = [];
        if ($request->filled('material_request')) {
            $mr = MaterialRequest::query()
                ->where('project_id', $project->id)
                ->find($request->integer('material_request'));
            if ($mr?->status->isProcurable()) {
                $prefill = $mr->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }

        return Inertia::render('Procurement/PurchaseOrders/Form', [
            'project' => ProjectHeader::for($project),
            'order' => null,
            'prefillItemIds' => $prefill,
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
        $items = $po->items()->with(['unit:id,symbol', 'materialRequestItem.materialRequest:id,request_number,source_label_snapshot,source_type', 'taxRate:id,name'])->get();

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'project' => ProjectHeader::for($project),
            'order' => [
                ...$this->header($po),
                ...$po->only([
                    'billing_address', 'shipping_address', 'consignee_name', 'consignee_email', 'consignee_gstin',
                    'place_of_supply_state', 'vendor_state_code', 'payment_terms', 'terms', 'document_terms_template_id',
                    'remarks', 'other_references', 'reference_number', 'dispatched_through', 'destination',
                    'delivery_terms', 'direct_justification', 'cancelled_reason', ...PurchaseOrder::MONEY_FIELDS,
                ]),
                'reference_date' => $po->reference_date?->toDateString(),
                'place_of_supply_label' => IndianState::tryFrom((string) $po->place_of_supply_state)?->label(),
                'tax_type_label' => $po->tax_type?->label(),
                'vendor' => ProcurementPresenter::vendor($po->vendor),
                'rfq' => ProcurementSettings::rfqEnabled() && $po->rfq
                    ? ['id' => $po->rfq->id, 'number' => $po->rfq->rfq_number, 'url' => route('projects.rfqs.show', [$project, $po->rfq->id])]
                    : null,
                'source_requests' => $items->map(fn (PurchaseOrderItem $i) => $i->materialRequestItem?->materialRequest?->request_number)->filter()->unique()->values()->all(),
                'quotation_number' => $po->quotation?->quotation_number,
                'approved_by' => $po->approver?->name,
                'approved_at' => $po->approved_at?->toIso8601String(),
                'cancelled_by' => $po->canceller?->name,
                'cancelled_at' => $po->cancelled_at?->toIso8601String(),
            ],
            'items' => $items->map(function (PurchaseOrderItem $i) {
                $ordered = Decimal::of($i->quantity);
                $received = Decimal::of($i->received_qty);
                $over = $received->minus($ordered);

                return [
                    ...$i->only(['id', 'item_code', 'description', 'hsn_sac', 'brand', 'make', 'quantity', 'rate', 'discount_percent', 'discount_amount', 'base_amount', 'taxable_amount',
                        'cgst_rate', 'cgst_amount', 'sgst_rate', 'sgst_amount', 'igst_rate', 'igst_amount', 'amount', 'received_qty']),
                    'due_date' => $i->due_date?->toDateString(),
                    'unit' => $i->unit?->symbol,
                    'remaining_qty' => ($over->isPositive() ? Decimal::zero() : $ordered->minus($received))->toQuantity(),
                    'over_received_qty' => ($over->isPositive() ? $over : Decimal::zero())->toQuantity(),
                    'tax_rate' => $i->taxRate?->name,
                    'material_request' => $i->materialRequestItem?->materialRequest?->request_number,
                    'material_request_source' => $i->materialRequestItem?->materialRequest?->source_label_snapshot,
                ];
            })->all(),
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
            'audit' => AuditPresenter::trail([$po]),
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
                ...$po->only([
                    'billing_address', 'shipping_address', 'consignee_name', 'consignee_email', 'consignee_gstin',
                    'place_of_supply_state', 'payment_terms', 'terms', 'document_terms_template_id', 'remarks',
                    'other_references', 'reference_number', 'dispatched_through', 'destination', 'delivery_terms',
                    'direct_justification', 'freight_amount', 'other_charges',
                ]),
                'po_date' => $po->po_date?->toDateString(),
                'delivery_date' => $po->delivery_date?->toDateString(),
                'reference_date' => $po->reference_date?->toDateString(),
                'is_direct' => $po->isDirect(),
                'vendor' => ProcurementPresenter::vendor($po->vendor()->first()),
                'items' => $items->map(fn (PurchaseOrderItem $i) => [
                    ...$i->only(['id', 'material_request_item_id', 'item_code', 'description', 'hsn_sac', 'brand', 'make', 'quantity', 'rate', 'discount_percent', 'tax_rate_id', 'received_qty']),
                    'due_date' => $i->due_date?->toDateString(),
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
    public function pdf(Request $request, Project $project, PurchaseOrder $purchaseOrder): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('export', $purchaseOrder);

        $company = Company::query()->findOrFail($purchaseOrder->company_id);
        $data = app(PurchaseOrderPdfDataBuilder::class)->build($purchaseOrder, $project, $company);
        $pdf = Pdf::loadView('pdf.purchase-order', $data)->setPaper('a4');

        $name = $purchaseOrder->po_number.($purchaseOrder->revision_no > 0 ? '-R'.$purchaseOrder->revision_no : '').'.pdf';

        return PdfResponder::send($pdf, $name, $request->routeIs('projects.purchase-orders.pdf.view'));
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
        $company = app(CurrentCompany::class)->require();
        $settings = DocumentPdfSettings::for($company);

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
            'termsTemplates' => DocumentTermsTemplate::query()
                ->where('document_type', 'purchase_order')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'payment_terms', 'general_terms'])
                ->map(fn (DocumentTermsTemplate $t) => [
                    'value' => $t->id,
                    'label' => $t->name,
                    'payment_terms' => $t->payment_terms,
                    'terms' => $t->general_terms,
                ])->all(),
            'invoiceTo' => [
                'name' => $company->legal_name ?: $company->name,
                'former_name' => $settings['former_name'],
                'address' => collect([
                    $company->address,
                    trim(($company->city ?? '').($company->pincode ? ' - '.$company->pincode : '')),
                ])->filter()->implode("\n"),
                'pan' => $company->pan,
                'gstin' => $company->gstin,
                'state' => IndianState::tryFrom((string) $company->state_code)?->label(),
                'state_code' => $company->state_code,
                'cin' => $settings['cin'],
                'email' => $company->email,
            ],
            'defaults' => [
                'place_of_supply_state' => $project->state_code,
                'shipping_address' => collect([$project->name, $project->address, $project->city])->filter()->implode("\n"),
                'consignee_name' => $project->name,
                'destination' => collect([$project->name, $project->city])->filter()->implode(', '),
                'today' => now()->toDateString(),
            ],
        ];
    }
}

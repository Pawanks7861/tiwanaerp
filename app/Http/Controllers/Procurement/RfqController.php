<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\RfqStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Procurement\RfqRequest;
use App\Models\Masters\Vendor;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\RfqVendor;
use App\Models\Procurement\VendorQuotation;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Procurement\ProcurementQuantityService;
use App\Services\Procurement\RfqService;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RfqController extends Controller
{
    public function __construct(
        private readonly RfqService $rfqs,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Rfq::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RfqStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->rfqs()
            ->withCount(['items', 'vendors', 'quotations'])
            ->with('comparison:id,rfq_id,status')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('rfq_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $page->getCollection()->each->setRelation('project', $project);

        return Inertia::render('Procurement/Rfqs/Index', [
            'project' => ProjectHeader::for($project),
            'rfqs' => $page->through(fn (Rfq $rfq) => [
                ...$this->header($rfq),
                'items_count' => $rfq->items_count,
                'vendors_count' => $rfq->vendors_count,
                'quotations_count' => $rfq->quotations_count,
                'comparison_status' => $rfq->comparison?->status->value,
            ]),
            'filters' => $filters,
            'statuses' => RfqStatus::options(),
            'can' => ['create' => $user->can('create', [Rfq::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [Rfq::class, $project]);

        return Inertia::render('Procurement/Rfqs/Form', [
            'project' => ProjectHeader::for($project),
            'rfq' => null,
            'preselect' => $request->integer('material_request') ?: null,
            ...$this->formOptions($project, null),
        ]);
    }

    public function store(RfqRequest $request, Project $project): RedirectResponse
    {
        $rfq = $this->rfqs->create($project, $request->validated());

        return redirect()->route('projects.rfqs.show', [$project, $rfq])->with('success', "RFQ {$rfq->rfq_number} saved as draft.");
    }

    public function show(Request $request, Project $project, Rfq $rfq): Response
    {
        Gate::authorize('view', $rfq);

        $user = $request->user();
        $items = $rfq->items()->with(['material:id,code,name', 'unit:id,symbol', 'materialRequestItem.materialRequest:id,request_number'])->get();
        $vendors = $rfq->vendors()->with('vendor:id,code,name,gstin,state_code,city,is_active')->get();
        $seeQuotes = $user->can('vendor_quotations.view');
        $quotations = $seeQuotes ? $rfq->quotations()->get()->keyBy('vendor_id') : collect();
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->first();
        $order = $rfq->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Cancelled->value)->first(['id', 'project_id', 'po_number', 'status']);

        return Inertia::render('Procurement/Rfqs/Show', [
            'project' => ProjectHeader::for($project),
            'rfq' => [
                ...$this->header($rfq),
                'terms' => $rfq->terms,
                'sent_at' => $rfq->sent_at?->toIso8601String(),
                'closed_at' => $rfq->closed_at?->toIso8601String(),
                'cancelled_reason' => $rfq->cancelled_reason,
            ],
            'items' => $items->map(fn (RfqItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'required_date' => $i->required_date?->toDateString(),
                'specification' => $i->specification,
                'material_request' => $i->materialRequestItem?->materialRequest?->request_number,
            ])->all(),
            'vendors' => $vendors->map(fn (RfqVendor $v) => [
                'id' => $v->id,
                'vendor' => $v->vendor?->only(['id', 'code', 'name', 'gstin', 'state_code', 'city', 'is_active']),
                'status' => $v->status->value,
                'status_label' => $v->status->label(),
                'sent_at' => $v->sent_at?->toIso8601String(),
                'responded_at' => $v->responded_at?->toIso8601String(),
                'quotation' => ($q = $quotations->get($v->vendor_id)) ? [
                    'id' => $q->id,
                    'quotation_number' => $q->quotation_number,
                    'quotation_date' => $q->quotation_date?->toDateString(),
                    'grand_total' => $q->grand_total,
                    'delivery_days' => $q->delivery_days,
                    'is_selected' => $q->is_selected,
                ] : null,
            ])->all(),
            'vendorOptions' => $user->can('manageVendors', $rfq) ? ProcurementPresenter::vendorOptions() : [],
            'comparison' => $comparison ? ['id' => $comparison->id, 'status' => $comparison->status->value, 'status_label' => $comparison->status->label()] : null,
            'purchaseOrder' => $order ? ['id' => $order->id, 'po_number' => $order->po_number, 'status' => $order->status->value, 'url' => route('projects.purchase-orders.show', [$project, $order->id])] : null,
            'attachments' => ProcurementPresenter::attachments($rfq),
            'can' => [
                'update' => $rfq->isEditable() && $user->can('update', $rfq),
                'delete' => $rfq->isEditable() && $user->can('delete', $rfq),
                'send' => $rfq->status === RfqStatus::Draft && $user->can('send', $rfq),
                'manage_vendors' => in_array($rfq->status, [RfqStatus::Draft, RfqStatus::Sent, RfqStatus::QuotesReceived], true)
                    && $user->can('manageVendors', $rfq),
                'close' => in_array($rfq->status, [RfqStatus::Sent, RfqStatus::QuotesReceived, RfqStatus::Evaluated], true)
                    && $user->can('close', $rfq),
                'cancel' => $rfq->status->isOpen() && $order === null && $user->can('cancel', $rfq),
                'quote' => $rfq->status->acceptsQuotations() && $user->can('create', [VendorQuotation::class, $rfq]),
                'edit_quote' => $rfq->status->acceptsQuotations() && $user->can('vendor_quotations.update'),
                'view_quotes' => $seeQuotes,
                'compare' => $user->can('view', [BidComparison::class, $rfq]) && ($comparison !== null || $rfq->status === RfqStatus::QuotesReceived),
                'create_po' => $comparison?->status === BidComparisonStatus::Approved && $order === null
                    && in_array($rfq->status, [RfqStatus::Evaluated, RfqStatus::Closed], true)
                    && $user->can('create', [PurchaseOrder::class, $project]),
                'attach' => $user->can('update', $rfq),
            ],
        ]);
    }

    public function edit(Project $project, Rfq $rfq): Response
    {
        Gate::authorize('update', $rfq);

        return Inertia::render('Procurement/Rfqs/Form', [
            'project' => ProjectHeader::for($project),
            'rfq' => [
                ...$this->header($rfq),
                'terms' => $rfq->terms,
                'items' => $rfq->items()->get()->map(fn (RfqItem $i) => [
                    'material_request_item_id' => $i->material_request_item_id,
                    'quantity' => $i->quantity,
                    'required_date' => $i->required_date?->toDateString(),
                    'specification' => $i->specification,
                ])->all(),
                'vendor_ids' => $rfq->vendors()->pluck('vendor_id')->all(),
            ],
            'preselect' => null,
            ...$this->formOptions($project, $rfq),
        ]);
    }

    public function update(RfqRequest $request, Project $project, Rfq $rfq): RedirectResponse
    {
        $this->rfqs->update($rfq, $request->validated());

        return redirect()->route('projects.rfqs.show', [$project, $rfq])->with('success', 'RFQ updated.');
    }

    public function destroy(Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('delete', $rfq);

        $this->rfqs->delete($rfq);

        return redirect()->route('projects.rfqs.index', $project)->with('success', "RFQ {$rfq->rfq_number} deleted.");
    }

    public function vendors(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('manageVendors', $rfq);

        $data = $request->validate([
            'vendor_ids' => ['present', 'array', 'max:50'],
            'vendor_ids.*' => ['integer', 'distinct', new ExistsInCompany(Vendor::class)],
        ], [], ['vendor_ids.*' => 'vendor']);

        $this->rfqs->syncVendors($rfq, $data['vendor_ids']);

        return back()->with('success', 'Vendors updated.');
    }

    public function send(Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('send', $rfq);

        $this->rfqs->send($rfq);

        return back()->with('success', 'RFQ marked as sent to the vendors.');
    }

    public function close(Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('close', $rfq);

        $this->rfqs->close($rfq);

        return back()->with('success', 'RFQ closed.');
    }

    public function cancel(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('cancel', $rfq);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->rfqs->cancel($rfq, $reason);

        return back()->with('success', 'RFQ cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Rfq $rfq): array
    {
        return [
            'id' => $rfq->id,
            'rfq_number' => $rfq->rfq_number,
            'title' => $rfq->title,
            'rfq_date' => $rfq->rfq_date?->toDateString(),
            'due_date' => $rfq->due_date?->toDateString(),
            'required_date' => $rfq->required_date?->toDateString(),
            'status' => $rfq->status->value,
            'status_label' => $rfq->status->label(),
        ];
    }

    /**
     * Approved MR lines of the project with their remaining quantity (excluding this RFQ).
     *
     * @return array<string, mixed>
     */
    private function formOptions(Project $project, ?Rfq $rfq): array
    {
        $current = $rfq ? $rfq->items()->pluck('material_request_item_id')->filter()->all() : [];

        $lines = MaterialRequestItem::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('materialRequest', fn (Builder $m) => $m->where('project_id', $project->id)->whereIn('status', ['approved', 'partially_ordered']))
                ->orWhereIn('id', $current))
            ->whereHas('materialRequest', fn (Builder $m) => $m->where('project_id', $project->id))
            ->with(['materialRequest:id,request_number,required_date,priority', 'material:id,code,name', 'unit:id,symbol'])
            ->orderBy('material_request_id')->orderBy('sort_order')
            ->limit(1000)
            ->get();

        $remaining = $this->quantities->remaining($lines, exceptRfqId: $rfq?->id);

        return [
            'lines' => $lines->map(fn (MaterialRequestItem $i) => [
                'id' => $i->id,
                'material_request_id' => $i->material_request_id,
                'request_number' => $i->materialRequest->request_number,
                'required_date' => $i->materialRequest->required_date?->toDateString(),
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'remaining_qty' => $remaining[$i->id],
            ])->filter(fn ($l) => in_array($l['id'], $current, true) || Decimal::of($l['remaining_qty'])->isPositive())->values()->all(),
            'vendors' => ProcurementPresenter::vendorOptions(),
            'today' => now()->toDateString(),
        ];
    }
}

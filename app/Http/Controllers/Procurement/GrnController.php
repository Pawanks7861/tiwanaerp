<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\Procurement\GrnStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Rules\ExistsInCompany;
use App\Services\Procurement\GrnService;
use App\Support\Procurement\ProcurementSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GrnController extends Controller
{
    public function __construct(
        private readonly GrnService $grns,
        private readonly ProcurementSettings $settings,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Grn::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(GrnStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->grns()
            ->with(['vendor:id,code,name', 'purchaseOrder:id,po_number', 'warehouse:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('grn_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('vendor_invoice_no', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Procurement/Grns/Index', [
            'project' => ProjectHeader::for($project),
            'grns' => $page->through(fn (Grn $grn) => [
                ...$this->header($grn),
                'vendor' => $grn->vendor?->only(['id', 'code', 'name']),
                'po_number' => $grn->purchaseOrder?->po_number,
                'warehouse' => $grn->warehouse?->name,
                'items_count' => $grn->items_count,
            ]),
            'filters' => $filters,
            'statuses' => GrnStatus::options(),
            'receivable' => $user->can('grn.create') ? $this->receivableOrders($project) : [],
            'can' => ['create' => $user->can('grn.create') && $user->can('viewAny', [Grn::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response|RedirectResponse
    {
        $po = PurchaseOrder::query()->where('project_id', $project->id)->find($request->integer('purchase_order'));
        if ($po === null) {
            return redirect()->route('projects.grns.index', $project)->with('error', 'Choose an approved purchase order to receive against.');
        }
        Gate::authorize('create', [Grn::class, $po]);

        return $this->form($project, $po, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $poId = $request->validate(['purchase_order_id' => ['required', 'integer']])['purchase_order_id'];
        $po = PurchaseOrder::query()->where('project_id', $project->id)->findOrFail($poId);
        Gate::authorize('create', [Grn::class, $po]);

        $grn = $this->grns->create($po, $this->validated($request, $project));

        return redirect()->route('projects.grns.show', [$project, $grn])->with('success', "GRN {$grn->grn_number} saved as draft.");
    }

    public function show(Request $request, Project $project, Grn $grn): Response
    {
        Gate::authorize('view', $grn);

        $user = $request->user();
        $grn->load(['vendor', 'purchaseOrder:id,po_number,revision_no,status', 'warehouse:id,code,name', 'approver:id,name']);
        $seeRates = $user->can('purchase.view');
        $items = $grn->items()->with(['purchaseOrderItem:id,item_code,description', 'unit:id,symbol'])->get();

        return Inertia::render('Procurement/Grns/Show', [
            'project' => ProjectHeader::for($project),
            'grn' => [
                ...$this->header($grn),
                ...$grn->only(['vendor_invoice_no', 'vendor_challan_no', 'vehicle_no', 'remarks']),
                'vendor_invoice_date' => $grn->vendor_invoice_date?->toDateString(),
                'vendor' => ProcurementPresenter::vendor($grn->vendor),
                'warehouse' => $grn->warehouse?->only(['id', 'code', 'name']),
                'purchase_order' => $grn->purchaseOrder ? [
                    'id' => $grn->purchaseOrder->id,
                    'po_number' => $grn->purchaseOrder->po_number,
                    'url' => $user->can('purchase.view') ? route('projects.purchase-orders.show', [$project, $grn->purchaseOrder->id]) : null,
                ] : null,
                'approved_by' => $grn->approver?->name,
                'approved_at' => $grn->approved_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (GrnItem $i) => [
                'id' => $i->id,
                'item_code' => $i->purchaseOrderItem?->item_code,
                'description' => $i->purchaseOrderItem?->description,
                'unit' => $i->unit?->symbol,
                ...$i->only(['ordered_qty', 'previously_received_qty', 'received_qty', 'rejected_qty', 'accepted_qty', 'rejection_reason']),
                'rate' => $seeRates ? $i->rate : null,
            ])->all(),
            'approval' => ProcurementPresenter::approval($grn, $user),
            'attachments' => ProcurementPresenter::attachments($grn),
            'audit' => AuditPresenter::trail([$grn]),
            'can' => [...$this->abilities($user, $grn), 'attach' => $user->can('update', $grn), 'view_rates' => $seeRates],
        ]);
    }

    public function edit(Project $project, Grn $grn): Response
    {
        Gate::authorize('update', $grn);

        $po = PurchaseOrder::query()->findOrFail($grn->purchase_order_id);

        return $this->form($project, $po, $grn);
    }

    public function update(Request $request, Project $project, Grn $grn): RedirectResponse
    {
        Gate::authorize('update', $grn);

        $this->grns->update($grn, $this->validated($request, $project));

        return redirect()->route('projects.grns.show', [$project, $grn])->with('success', 'GRN updated.');
    }

    public function destroy(Project $project, Grn $grn): RedirectResponse
    {
        Gate::authorize('delete', $grn);

        $this->grns->delete($grn);

        return redirect()->route('projects.grns.index', $project)->with('success', "GRN {$grn->grn_number} deleted.");
    }

    public function submit(Request $request, Project $project, Grn $grn): RedirectResponse
    {
        Gate::authorize('submit', $grn);

        $this->grns->submit($grn, $request->user());

        return back()->with('success', 'GRN submitted for approval.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project): array
    {
        return $request->validate([
            'warehouse_id' => ['nullable', new ExistsInCompany(Warehouse::class, fn (Builder $q) => $q
                ->where('is_active', true)->where(fn (Builder $w) => $w->whereNull('project_id')->orWhere('project_id', $project->id)))],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:50'],
            'vendor_invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'vendor_challan_no' => ['nullable', 'string', 'max:50'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.received_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.rejected_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'warehouse_id' => 'warehouse',
            'items.*.received_qty' => 'received quantity',
            'items.*.rejected_qty' => 'rejected quantity',
        ]);
    }

    private function form(Project $project, PurchaseOrder $po, ?Grn $grn): Response
    {
        $lines = $grn ? $grn->items()->get()->keyBy('purchase_order_item_id') : collect();
        $seeRates = request()->user()->can('purchase.view');

        return Inertia::render('Procurement/Grns/Form', [
            'project' => ProjectHeader::for($project),
            'purchaseOrder' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'po_date' => $po->po_date?->toDateString(),
                'vendor' => ProcurementPresenter::vendor($po->vendor()->first()),
            ],
            'grn' => $grn ? [
                'id' => $grn->id,
                'grn_number' => $grn->grn_number,
                ...$grn->only(['warehouse_id', 'vendor_invoice_no', 'vendor_challan_no', 'vehicle_no', 'remarks']),
                'receipt_date' => $grn->receipt_date?->toDateString(),
                'vendor_invoice_date' => $grn->vendor_invoice_date?->toDateString(),
            ] : null,
            'lines' => array_map(fn (array $line) => [
                ...$line,
                'rate' => $seeRates ? $line['rate'] : null,
                'received_qty_input' => $lines->get($line['purchase_order_item_id'])?->received_qty,
                'rejected_qty_input' => $lines->get($line['purchase_order_item_id'])?->rejected_qty,
                'rejection_reason' => $lines->get($line['purchase_order_item_id'])?->rejection_reason,
            ], $this->grns->receivableLines($po, $grn)),
            'warehouses' => ProcurementPresenter::warehouseOptions($project),
            'tolerance' => $this->settings->grnTolerancePercent($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function receivableOrders(Project $project): array
    {
        return $project->purchaseOrders()->whereIn('status', ['approved', 'partially_received'])
            ->with('vendor:id,name')->latest('id')->limit(200)->get(['id', 'po_number', 'vendor_id', 'status'])
            ->map(fn (PurchaseOrder $po) => ['value' => $po->id, 'label' => $po->po_number, 'description' => $po->vendor?->name.' · '.$po->status->label()])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Grn $grn): array
    {
        return [
            'id' => $grn->id,
            'grn_number' => $grn->grn_number,
            'receipt_date' => $grn->receipt_date?->toDateString(),
            'status' => $grn->status->value,
            'status_label' => $grn->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, Grn $grn): array
    {
        $editable = $grn->isEditable();

        return [
            'update' => $editable && $user->can('update', $grn),
            'delete' => $editable && $user->can('delete', $grn),
            'submit' => $editable && $user->can('submit', $grn),
        ];
    }
}

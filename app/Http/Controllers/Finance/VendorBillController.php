<?php

namespace App\Http\Controllers\Finance;

use App\Enums\CostHead;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Finance\VendorBillType;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Finance\VendorBill;
use App\Models\Finance\VendorBillItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\PayableService;
use App\Services\Finance\VendorBillService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VendorBillController extends Controller
{
    public function __construct(private readonly VendorBillService $bills, private readonly PayableService $payables) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [VendorBill::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VendorBillStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $page = $project->vendorBills()->with(['vendor:id,name', 'purchaseOrder:id,po_number'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('bill_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('vendor_invoice_no', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Finance/VendorBills/Index', [
            'project' => ProjectHeader::for($project),
            'bills' => $page->through(fn (VendorBill $b) => $this->header($b)),
            'filters' => $filters,
            'statuses' => VendorBillStatus::options(),
            'can' => ['create' => $request->user()->can('create', [VendorBill::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [VendorBill::class, $project]);

        return $this->form($project, null, $request->integer('purchase_order_id') ?: null, $request->string('bill_type')->toString() ?: 'purchase_order');
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [VendorBill::class, $project]);

        $bill = $this->bills->create($project, $this->validated($request, true));

        return redirect()->route('projects.vendor-bills.show', [$project, $bill])->with('success', "Vendor bill {$bill->bill_number} saved as draft.");
    }

    public function show(Request $request, Project $project, VendorBill $vendorBill): Response
    {
        Gate::authorize('view', $vendorBill);
        $user = $request->user();
        $bill = $vendorBill->load(['vendor:id,code,name,gstin,state_code', 'purchaseOrder:id,po_number', 'task:id,wbs_code,name', 'approver:id,name', 'creator:id,name']);
        $items = $bill->items()->with(['unit:id,symbol', 'grnItem:id,grn_id,received_qty,accepted_qty', 'grnItem.grn:id,grn_number', 'purchaseOrderItem:id,quantity'])->get();
        $billed = $this->billedElsewhere($bill, $items);

        return Inertia::render('Finance/VendorBills/Show', [
            'project' => ProjectHeader::for($project),
            'bill' => [
                ...$this->header($bill),
                ...$bill->only(['vendor_invoice_no', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tds_percent', 'tds_amount', 'remarks', 'place_of_supply_state']),
                'vendor_invoice_date' => $bill->vendor_invoice_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'tax_type' => $bill->tax_type->value,
                'cost_head_label' => $bill->cost_head?->label(),
                'task' => $bill->task ? trim($bill->task->wbs_code.' '.$bill->task->name) : null,
                'vendor' => $bill->vendor?->only(['id', 'code', 'name', 'gstin', 'state_code']),
                'purchase_order' => $bill->purchaseOrder?->only(['id', 'po_number']),
                'outstanding' => $bill->status->isApproved() ? $this->payables->outstanding($bill)->toMoney() : null,
                'created_by' => $bill->creator?->name,
                'approved_by' => $bill->approver?->name,
                'approved_at' => $bill->approved_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (VendorBillItem $i) => [
                'id' => $i->id,
                ...$i->only(['description', 'hsn_sac', 'quantity', 'rate', 'discount_percent', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'amount']),
                'unit' => $i->unit?->symbol,
                'grn_number' => $i->grnItem?->grn?->grn_number,
                'po_qty' => $i->purchaseOrderItem?->quantity,
                'grn_received_qty' => $i->grnItem?->received_qty,
                'grn_accepted_qty' => $i->grnItem?->accepted_qty,
                'billed_elsewhere' => $i->grn_item_id ? ($billed[$i->grn_item_id] ?? '0.0000') : null,
            ])->all(),
            'approval' => ProcurementPresenter::approval($bill, $user),
            'attachments' => ProcurementPresenter::attachments($bill),
            'can' => $this->abilities($user, $bill),
        ]);
    }

    public function edit(Project $project, VendorBill $vendorBill): Response
    {
        Gate::authorize('update', $vendorBill);

        return $this->form($project, $vendorBill, $vendorBill->purchase_order_id, $vendorBill->bill_type->value);
    }

    public function update(Request $request, Project $project, VendorBill $vendorBill): RedirectResponse
    {
        Gate::authorize('update', $vendorBill);

        $this->bills->update($vendorBill, $this->validated($request, false, $vendorBill->bill_type));

        return redirect()->route('projects.vendor-bills.show', [$project, $vendorBill])->with('success', 'Vendor bill updated.');
    }

    public function destroy(Project $project, VendorBill $vendorBill): RedirectResponse
    {
        Gate::authorize('delete', $vendorBill);

        $this->bills->delete($vendorBill);

        return redirect()->route('projects.vendor-bills.index', $project)->with('success', "Vendor bill {$vendorBill->bill_number} deleted.");
    }

    public function submit(Request $request, Project $project, VendorBill $vendorBill): RedirectResponse
    {
        Gate::authorize('submit', $vendorBill);

        $this->bills->submit($vendorBill, $request->user());

        return back()->with('success', 'Vendor bill submitted for approval.');
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

    private function form(Project $project, ?VendorBill $bill, ?int $purchaseOrderId, string $billType): Response
    {
        $orders = $project->purchaseOrders()->with('vendor:id,name')
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed])
            ->whereHas('grns', fn ($q) => $q->where('status', 'approved'))
            ->orderByDesc('id')->get();
        $selected = $purchaseOrderId ? PurchaseOrder::query()->where('project_id', $project->id)->with('vendor:id,name')->find($purchaseOrderId) : null;
        $existing = $bill ? $bill->items()->get() : collect();

        return Inertia::render('Finance/VendorBills/Form', [
            'project' => ProjectHeader::for($project),
            'billType' => $billType === 'direct' ? 'direct' : 'purchase_order',
            'bill' => $bill ? [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                ...$bill->only(['vendor_id', 'purchase_order_id', 'vendor_invoice_no', 'tds_percent', 'remarks', 'task_id', 'boq_item_id']),
                'cost_head' => $bill->cost_head?->value,
                'vendor_invoice_date' => $bill->vendor_invoice_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'items' => $bill->isDirect() ? $existing->map(fn (VendorBillItem $i) => $i->only(['description', 'hsn_sac', 'unit_id', 'quantity', 'rate', 'discount_percent', 'tax_rate_id']))->all() : [],
            ] : null,
            'purchaseOrders' => $orders->map(fn (PurchaseOrder $o) => [
                'value' => $o->id,
                'label' => $o->po_number,
                'description' => $o->vendor?->name,
            ])->values()->all(),
            'purchaseOrder' => $selected ? [
                'id' => $selected->id,
                'po_number' => $selected->po_number,
                'vendor' => $selected->vendor?->name,
                'tax_type' => $selected->tax_type->value,
                'lines' => array_map(fn (array $line) => [
                    ...$line,
                    'quantity' => $existing->firstWhere('grn_item_id', $line['grn_item_id'])?->quantity,
                ], $this->bills->billableGrnLines($selected, $bill)),
            ] : null,
            'vendors' => ProcurementPresenter::vendorOptions(),
            'units' => ProcurementPresenter::unitOptions(),
            'taxRates' => ProcurementPresenter::taxRateOptions(),
            'tasks' => ProcurementPresenter::taskOptions($project),
            'boqItems' => ProcurementPresenter::boqItemOptions($project),
            'costHeads' => CostHead::options(),
            'projectState' => $project->state_code,
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * Quantity of each GRN line on the vendor's other submitted / approved bills.
     *
     * @return array<int, string>
     */
    private function billedElsewhere(VendorBill $bill, Collection $items): array
    {
        $ids = $items->pluck('grn_item_id')->filter()->all();
        if ($ids === []) {
            return [];
        }

        return VendorBillItem::query()->whereIn('grn_item_id', $ids)
            ->whereHas('bill', fn ($q) => $q->whereNull('deleted_at')->whereKeyNot($bill->id)
                ->whereIn('status', [VendorBillStatus::Submitted, ...VendorBillStatus::approvedStates()]))
            ->get(['grn_item_id', 'quantity'])
            ->groupBy('grn_item_id')
            ->map(fn ($rows) => Decimal::sum($rows->pluck('quantity')->all())->toQuantity())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(VendorBill $bill): array
    {
        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'vendor_invoice_no' => $bill->vendor_invoice_no,
            'vendor_invoice_date' => $bill->vendor_invoice_date?->toDateString(),
            'bill_type' => $bill->bill_type->value,
            'bill_type_label' => $bill->bill_type->label(),
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            'vendor_name' => $bill->vendor?->name,
            'po_number' => $bill->purchaseOrder?->po_number,
            'subtotal' => $bill->subtotal,
            'total_amount' => $bill->total_amount,
            'net_payable' => $bill->net_payable,
            'paid_amount' => $bill->paid_amount,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, VendorBill $bill): array
    {
        $editable = $bill->isEditable();

        return [
            'update' => $editable && $user->can('update', $bill),
            'delete' => $editable && $user->can('delete', $bill),
            'submit' => $editable && $user->can('submit', $bill),
            'attach' => $editable && $user->can('update', $bill),
        ];
    }
}

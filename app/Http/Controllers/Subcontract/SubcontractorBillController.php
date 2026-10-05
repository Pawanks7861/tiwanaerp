<?php

namespace App\Http\Controllers\Subcontract;

use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Tally\TallyStatusPresenter;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\SubcontractorBillItem;
use App\Models\Subcontract\WorkOrder;
use App\Models\User;
use App\Services\Subcontract\SubcontractorBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubcontractorBillController extends Controller
{
    public function __construct(private readonly SubcontractorBillService $bills) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [SubcontractorBill::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SubcontractorBillStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->subcontractorBills()
            ->with(['subcontractor:id,name', 'workOrder:id,wo_number'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('bill_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('subcontractor_invoice_no', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Subcontract/Bills/Index', [
            'project' => ProjectHeader::for($project),
            'bills' => $page->through(fn (SubcontractorBill $b) => $this->header($b)),
            'filters' => $filters,
            'statuses' => SubcontractorBillStatus::options(),
            'can' => ['create' => $request->user()->can('create', [SubcontractorBill::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [SubcontractorBill::class, $project]);

        return $this->form($project, null, $request->integer('work_order_id') ?: null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [SubcontractorBill::class, $project]);

        $bill = $this->bills->create($project, $this->validated($request, true));

        return redirect()->route('projects.subcontractor-bills.show', [$project, $bill])->with('success', "Bill {$bill->bill_number} saved as draft.");
    }

    public function show(Request $request, Project $project, SubcontractorBill $subcontractorBill): Response
    {
        Gate::authorize('view', $subcontractorBill);
        $user = $request->user();
        $bill = $subcontractorBill->load(['subcontractor:id,code,name,gstin', 'workOrder:id,wo_number,advance_amount,status', 'certifier:id,name', 'reopener:id,name', 'creator:id,name']);

        $items = $bill->items()->with(['workOrderItem:id,description,unit_id,boq_item_id,boq_line_uid', 'workOrderItem.unit:id,symbol', 'workOrderItem.boqItem:id,item_code,name'])->get();

        return Inertia::render('Subcontract/Bills/Show', [
            'project' => ProjectHeader::for($project),
            'bill' => [
                ...$this->header($bill),
                ...$bill->only(['subcontractor_invoice_no', 'remarks', 'tax_percent', 'retention_percent', 'tds_percent', 'tax_amount',
                    'retention_amount', 'advance_recovery', 'tds_amount', 'other_deductions', 'revision', 'reopen_reason']),
                'period_from' => $bill->period_from?->toDateString(),
                'period_to' => $bill->period_to?->toDateString(),
                'subcontractor' => $bill->subcontractor?->only(['id', 'code', 'name', 'gstin']),
                'work_order' => $bill->workOrder ? ['id' => $bill->workOrder->id, 'wo_number' => $bill->workOrder->wo_number] : null,
                'advance_balance' => $bill->workOrder ? $this->bills->advanceBalance($bill->workOrder, $bill->id)->toMoney() : '0.00',
                'created_by' => $bill->creator?->name,
                'certified_by' => $bill->certifier?->name,
                'certified_at' => $bill->certified_at?->toIso8601String(),
                'reopened_by' => $bill->reopener?->name,
                'reopened_at' => $bill->reopened_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (SubcontractorBillItem $i) => [
                'id' => $i->id,
                'description' => $i->workOrderItem?->description,
                'unit' => $i->workOrderItem?->unit?->symbol,
                'boq_item' => $i->workOrderItem?->boqItem ? trim($i->workOrderItem->boqItem->item_code.' '.$i->workOrderItem->boqItem->name) : null,
                ...$i->only(['wo_qty', 'previous_qty', 'claimed_qty', 'certified_qty', 'cumulative_qty', 'rate', 'amount']),
            ])->all(),
            'approval' => ProcurementPresenter::approval($bill, $user),
            'attachments' => ProcurementPresenter::attachments($bill),
            'can' => $this->abilities($user, $bill),
            'tally' => app(TallyStatusPresenter::class)->for($bill),
        ]);
    }

    public function edit(Project $project, SubcontractorBill $subcontractorBill): Response
    {
        Gate::authorize('update', $subcontractorBill);

        return $this->form($project, $subcontractorBill, $subcontractorBill->work_order_id);
    }

    public function update(Request $request, Project $project, SubcontractorBill $subcontractorBill): RedirectResponse
    {
        Gate::authorize('update', $subcontractorBill);

        $this->bills->update($subcontractorBill, $this->validated($request, false));

        return redirect()->route('projects.subcontractor-bills.show', [$project, $subcontractorBill])->with('success', 'Bill updated.');
    }

    public function destroy(Project $project, SubcontractorBill $subcontractorBill): RedirectResponse
    {
        Gate::authorize('delete', $subcontractorBill);

        $this->bills->delete($subcontractorBill);

        return redirect()->route('projects.subcontractor-bills.index', $project)->with('success', "Bill {$subcontractorBill->bill_number} deleted.");
    }

    public function submit(Request $request, Project $project, SubcontractorBill $subcontractorBill): RedirectResponse
    {
        Gate::authorize('submit', $subcontractorBill);

        $this->bills->submit($subcontractorBill, $request->user());

        return back()->with('success', 'Bill submitted for certification.');
    }

    /** Certifier's adjustment while the bill awaits their approval. */
    public function adjust(Request $request, Project $project, SubcontractorBill $subcontractorBill): RedirectResponse
    {
        Gate::authorize('certify', $subcontractorBill);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.id' => ['required', 'integer'],
            'items.*.certified_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ]);

        $this->bills->adjust($subcontractorBill, $data);

        return back()->with('success', 'Certified quantities updated. Approve the bill to certify it.');
    }

    public function reverse(Request $request, Project $project, SubcontractorBill $subcontractorBill): RedirectResponse
    {
        Gate::authorize('reverse', $subcontractorBill);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->bills->reverse($subcontractorBill, $request->user(), $reason);

        return back()->with('success', 'Certification reversed; the subcontract cost was reversed and the bill is a draft again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? ['work_order_id' => ['required', 'integer']] : []),
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'subcontractor_invoice_no' => ['nullable', 'string', 'max:60'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.work_order_item_id' => ['required', 'integer'],
            'items.*.claimed_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.certified_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
        ], [], ['work_order_id' => 'work order', 'items.*.claimed_qty' => 'claimed quantity']);
    }

    private function form(Project $project, ?SubcontractorBill $bill, ?int $workOrderId): Response
    {
        $orders = $project->workOrders()->with('subcontractor:id,name')
            ->whereIn('status', [WorkOrderStatus::Approved, WorkOrderStatus::InProgress, WorkOrderStatus::Completed])
            ->orderByDesc('id')->get();
        if ($bill) {
            $orders = $orders->push($bill->workOrder)->unique('id');
        }

        $selected = $workOrderId ? $orders->firstWhere('id', $workOrderId) : null;
        $existing = $bill ? $bill->items()->get()->keyBy('work_order_item_id') : collect();

        return Inertia::render('Subcontract/Bills/Form', [
            'project' => ProjectHeader::for($project),
            'bill' => $bill ? [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'work_order_id' => $bill->work_order_id,
                ...$bill->only(['subcontractor_invoice_no', 'remarks', 'advance_recovery', 'other_deductions']),
                'bill_date' => $bill->bill_date?->toDateString(),
                'period_from' => $bill->period_from?->toDateString(),
                'period_to' => $bill->period_to?->toDateString(),
            ] : null,
            'workOrders' => $orders->map(fn (WorkOrder $o) => [
                'value' => $o->id,
                'label' => $o->wo_number,
                'description' => $o->subcontractor?->name,
            ])->values()->all(),
            'workOrder' => $selected ? [
                'id' => $selected->id,
                'wo_number' => $selected->wo_number,
                'subcontractor' => $selected->subcontractor?->name,
                'tax_percent' => $selected->tax_percent,
                'retention_percent' => $selected->retention_percent,
                'tds_percent' => $selected->tds_percent,
                'advance_balance' => $this->bills->advanceBalance($selected, $bill?->id)->toMoney(),
                'lines' => array_map(fn (array $line) => [
                    ...$line,
                    'claimed_qty' => $existing->get($line['id'])?->claimed_qty,
                    'certified_qty' => $existing->get($line['id'])?->certified_qty,
                ], $this->bills->billableLines($selected, $bill)),
            ] : null,
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(SubcontractorBill $bill): array
    {
        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'bill_date' => $bill->bill_date?->toDateString(),
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            'subcontractor_name' => $bill->subcontractor?->name,
            'wo_number' => $bill->workOrder?->wo_number,
            'gross_amount' => $bill->gross_amount,
            'net_payable' => $bill->net_payable,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, SubcontractorBill $bill): array
    {
        $editable = $bill->isEditable();
        $submitted = $bill->status === SubcontractorBillStatus::Submitted;

        return [
            'update' => $editable && $user->can('update', $bill),
            'delete' => $editable && $bill->revision === 0 && $user->can('delete', $bill),
            'submit' => $editable && $user->can('submit', $bill),
            'certify' => $submitted && $user->can('certify', $bill),
            'reverse' => $bill->status === SubcontractorBillStatus::Certified && $user->can('reverse', $bill),
            'attach' => $editable && $user->can('update', $bill),
        ];
    }
}

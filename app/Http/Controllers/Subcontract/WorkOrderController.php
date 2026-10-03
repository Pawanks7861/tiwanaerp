<?php

namespace App\Http\Controllers\Subcontract;

use App\Enums\Subcontract\MilestoneStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Models\Subcontract\WorkOrderItem;
use App\Models\Subcontract\WorkOrderMilestone;
use App\Models\User;
use App\Services\Subcontract\SubcontractorBillService;
use App\Services\Subcontract\WorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderService $orders,
        private readonly SubcontractorBillService $bills,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [WorkOrder::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(WorkOrderStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->workOrders()
            ->with('subcontractor:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('wo_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('subcontractor', fn ($s) => $s->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Subcontract/WorkOrders/Index', [
            'project' => ProjectHeader::for($project),
            'orders' => $page->through(fn (WorkOrder $o) => [...$this->header($o), 'items_count' => $o->items_count]),
            'filters' => $filters,
            'statuses' => WorkOrderStatus::options(),
            'can' => ['create' => $request->user()->can('create', [WorkOrder::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [WorkOrder::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [WorkOrder::class, $project]);

        $order = $this->orders->create($project, $this->validated($request));

        return redirect()->route('projects.work-orders.show', [$project, $order])->with('success', "Work order {$order->wo_number} saved as draft.");
    }

    public function show(Request $request, Project $project, WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);
        $user = $request->user();
        $order = $workOrder->load(['subcontractor:id,code,name,gstin,mobile', 'taxRate:id,name', 'approver:id,name', 'closer:id,name', 'creator:id,name']);

        $items = $order->items()->with(['unit:id,symbol', 'boqItem:id,item_code,name', 'task:id,wbs_code,name'])->get();

        return Inertia::render('Subcontract/WorkOrders/Show', [
            'project' => ProjectHeader::for($project),
            'order' => [
                ...$this->header($order),
                ...$order->only(['scope', 'terms', 'retention_percent', 'advance_amount', 'tax_percent', 'tds_percent', 'subtotal', 'tax_amount', 'cancellation_reason']),
                'subcontractor' => $order->subcontractor?->only(['id', 'code', 'name', 'gstin', 'mobile']),
                'tax_rate' => $order->taxRate?->name,
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
                'created_by' => $order->creator?->name,
                'approved_by' => $order->approver?->name,
                'approved_at' => $order->approved_at?->toIso8601String(),
                'closed_by' => $order->closer?->name,
                'closed_at' => $order->closed_at?->toIso8601String(),
                'advance_balance' => $this->bills->advanceBalance($order)->toMoney(),
            ],
            'items' => $items->map(fn (WorkOrderItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'rate' => $i->rate,
                'amount' => $i->amount,
                'certified_qty' => $i->certified_qty,
                'balance_qty' => $i->balanceQty()->toQuantity(),
                'boq_item' => $i->boqItem ? trim($i->boqItem->item_code.' '.$i->boqItem->name) : null,
                'boq_line_uid' => $i->boq_line_uid,
                'task' => $i->task ? trim($i->task->wbs_code.' '.$i->task->name) : null,
            ])->all(),
            'milestones' => $order->milestones()->get()->map(fn (WorkOrderMilestone $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'due_date' => $m->due_date?->toDateString(),
                'amount_percent' => $m->amount_percent,
                'status' => $m->status->value,
                'achieved_at' => $m->achieved_at?->toIso8601String(),
            ])->all(),
            'bills' => $order->bills()->latest('id')->get()->map(fn (SubcontractorBill $b) => [
                'id' => $b->id,
                'bill_number' => $b->bill_number,
                'bill_date' => $b->bill_date?->toDateString(),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'gross_amount' => $b->gross_amount,
                'net_payable' => $b->net_payable,
            ])->all(),
            'approval' => ProcurementPresenter::approval($order, $user),
            'attachments' => ProcurementPresenter::attachments($order),
            'milestoneStatuses' => MilestoneStatus::options(),
            'can' => $this->abilities($user, $order),
        ]);
    }

    public function edit(Project $project, WorkOrder $workOrder): Response
    {
        Gate::authorize('update', $workOrder);

        return $this->form($project, $workOrder);
    }

    public function update(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        $this->orders->update($workOrder, $this->validated($request));

        return redirect()->route('projects.work-orders.show', [$project, $workOrder])->with('success', 'Work order updated.');
    }

    public function destroy(Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('delete', $workOrder);

        $this->orders->delete($workOrder);

        return redirect()->route('projects.work-orders.index', $project)->with('success', "Work order {$workOrder->wo_number} deleted.");
    }

    public function submit(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('submit', $workOrder);

        $this->orders->submit($workOrder, $request->user());

        return back()->with('success', 'Work order submitted for approval.');
    }

    public function complete(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('complete', $workOrder);

        $this->orders->complete($workOrder, $request->user());

        return back()->with('success', 'Work order marked completed.');
    }

    public function close(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('close', $workOrder);

        $this->orders->close($workOrder, $request->user());

        return back()->with('success', 'Work order closed.');
    }

    public function cancel(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('cancel', $workOrder);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->orders->cancel($workOrder, $request->user(), $reason);

        return back()->with('success', 'Work order cancelled.');
    }

    public function milestones(Request $request, Project $project, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('updateMilestones', $workOrder);

        $rows = $request->validate([
            'milestones' => ['required', 'array', 'max:100'],
            'milestones.*.id' => ['required', 'integer'],
            'milestones.*.status' => ['required', Rule::enum(MilestoneStatus::class)],
        ])['milestones'];

        $this->orders->updateMilestones($workOrder, $rows);

        return back()->with('success', 'Milestones updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'subcontractor_id' => ['required', 'integer'],
            'wo_date' => ['required', 'date'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'retention_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'advance_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'tax_rate_id' => ['nullable', 'integer'],
            'tds_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'milestones' => ['nullable', 'array', 'max:50'],
            'milestones.*.name' => ['required', 'string', 'max:200'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.amount_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ], [], [
            'subcontractor_id' => 'subcontractor',
            'items.*.quantity' => 'quantity',
            'items.*.rate' => 'rate',
            'milestones.*.amount_percent' => 'milestone %',
        ]);
    }

    private function form(Project $project, ?WorkOrder $order): Response
    {
        return Inertia::render('Subcontract/WorkOrders/Form', [
            'project' => ProjectHeader::for($project),
            'order' => $order ? [
                'id' => $order->id,
                'wo_number' => $order->wo_number,
                ...$order->only(['subcontractor_id', 'scope', 'retention_percent', 'advance_amount', 'tax_rate_id', 'tds_percent', 'terms']),
                'wo_date' => $order->wo_date?->toDateString(),
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
                'items' => $order->items()->get()->map(fn (WorkOrderItem $i) => $i->only(['boq_item_id', 'task_id', 'description', 'unit_id', 'quantity', 'rate']))->all(),
                'milestones' => $order->milestones()->get()->map(fn (WorkOrderMilestone $m) => [
                    'name' => $m->name, 'due_date' => $m->due_date?->toDateString(), 'amount_percent' => $m->amount_percent,
                ])->all(),
            ] : null,
            'options' => [
                'subcontractors' => Subcontractor::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Subcontractor $s) => ['value' => $s->id, 'label' => $s->name, 'description' => $s->code])->all(),
                'tax_rates' => ProcurementPresenter::taxRateOptions(),
                'units' => ProcurementPresenter::unitOptions(),
                'boq_items' => ProcurementPresenter::boqItemOptions($project),
                'tasks' => ProcurementPresenter::taskOptions($project),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(WorkOrder $order): array
    {
        return [
            'id' => $order->id,
            'wo_number' => $order->wo_number,
            'wo_date' => $order->wo_date?->toDateString(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'subcontractor_name' => $order->subcontractor?->name,
            'total_value' => $order->total_value,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, WorkOrder $order): array
    {
        $editable = $order->isEditable();
        $status = $order->status;
        $billable = $status->isBillable();
        $openBills = SubcontractorBill::query()->where('work_order_id', $order->id)->exists();

        return [
            'update' => $editable && $user->can('update', $order),
            'delete' => $status === WorkOrderStatus::Draft && $user->can('delete', $order),
            'submit' => $editable && $user->can('submit', $order),
            'complete' => in_array($status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true) && $user->can('complete', $order),
            'close' => $billable && $user->can('close', $order),
            'cancel' => in_array($status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true) && ! $openBills && $user->can('cancel', $order),
            'milestones' => $billable && $user->can('updateMilestones', $order),
            'create_bill' => $billable && $user->can('create', [SubcontractorBill::class, $order->project]),
            'attach' => $editable && $user->can('update', $order),
        ];
    }
}

<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\StockTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\Inventory\StockTransferReceipt;
use App\Models\Inventory\StockTransferReceiptItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\StockTransferService;
use App\Support\Inventory\InventoryScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly InventoryScope $scope,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [StockTransfer::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StockTransferStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->stockTransfers()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('transfer_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Transfers/Index', [
            'project' => ProjectHeader::for($project),
            'transfers' => $page->through(fn (StockTransfer $t) => [
                ...$this->header($t),
                'from' => $t->fromWarehouse?->name,
                'to' => $t->toWarehouse?->name,
                'items_count' => $t->items_count,
            ]),
            'filters' => $filters,
            'statuses' => StockTransferStatus::options(),
            'can' => ['create' => $request->user()->can('create', [StockTransfer::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [StockTransfer::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [StockTransfer::class, $project]);

        $transfer = $this->transfers->create($project, $this->validated($request));

        return redirect()->route('projects.stock-transfers.show', [$project, $transfer])->with('success', "Transfer {$transfer->transfer_number} saved as draft.");
    }

    public function show(Request $request, Project $project, StockTransfer $stockTransfer): Response
    {
        Gate::authorize('view', $stockTransfer);

        $user = $request->user();
        $transfer = $stockTransfer->load(['fromWarehouse:id,code,name', 'toWarehouse:id,code,name', 'dispatcher:id,name', 'closer:id,name', 'creator:id,name']);
        $valuation = InventoryPresenter::seesValuation($user);
        $items = $transfer->items()->with(['material:id,code,name', 'unit:id,symbol'])->get();
        $receipts = $transfer->receipts()->with(['items.transferItem.material:id,name', 'creator:id,name'])->get();

        return Inertia::render('Inventory/Transfers/Show', [
            'project' => ProjectHeader::for($project),
            'transfer' => [
                ...$this->header($transfer),
                ...$transfer->only(['vehicle_no', 'remarks', 'close_reason']),
                'from' => $transfer->fromWarehouse?->only(['id', 'code', 'name']),
                'to' => $transfer->toWarehouse?->only(['id', 'code', 'name']),
                'created_by' => $transfer->creator?->name,
                'dispatched_by' => $transfer->dispatcher?->name,
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                'completed_at' => $transfer->completed_at?->toIso8601String(),
                'closed_by' => $transfer->closer?->name,
                'closed_at' => $transfer->closed_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (StockTransferItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'received_qty' => $i->received_qty,
                'short_closed_qty' => $i->short_closed_qty,
                'in_transit_qty' => $transfer->dispatched_at ? $i->inTransitQty()->toQuantity() : null,
                'remarks' => $i->remarks,
                ...($valuation ? ['unit_cost' => $i->unit_cost, 'value' => $i->value, 'received_value' => $i->received_value] : []),
            ])->all(),
            'receipts' => $receipts->map(fn (StockTransferReceipt $r) => [
                'id' => $r->id,
                'receipt_date' => $r->receipt_date?->toDateString(),
                'remarks' => $r->remarks,
                'received_by' => $r->creator?->name,
                'items' => $r->items->map(fn (StockTransferReceiptItem $ri) => [
                    'material' => $ri->transferItem?->material?->name,
                    'quantity' => $ri->quantity,
                    ...($valuation ? ['value' => $ri->value] : []),
                ])->all(),
            ])->all(),
            'attachments' => ProcurementPresenter::attachments($transfer),
            'receipt_key' => (string) Str::uuid(),
            'today' => now()->toDateString(),
            'can' => [...$this->abilities($user, $transfer), 'attach' => $transfer->isEditable() && $user->can('update', $transfer), 'view_valuation' => $valuation],
        ]);
    }

    public function edit(Project $project, StockTransfer $stockTransfer): Response
    {
        Gate::authorize('update', $stockTransfer);

        return $this->form($project, $stockTransfer);
    }

    public function update(Request $request, Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('update', $stockTransfer);

        $this->transfers->update($stockTransfer, $this->validated($request));

        return redirect()->route('projects.stock-transfers.show', [$project, $stockTransfer])->with('success', 'Transfer updated.');
    }

    public function destroy(Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('delete', $stockTransfer);

        $this->transfers->delete($stockTransfer);

        return redirect()->route('projects.stock-transfers.index', $project)->with('success', "Transfer {$stockTransfer->transfer_number} deleted.");
    }

    public function dispatch(Request $request, Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('dispatch', $stockTransfer);

        $this->transfers->dispatch($stockTransfer, $request->user());

        return back()->with('success', 'Transfer dispatched; the goods are now in transit.');
    }

    public function receive(Request $request, Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.stock_transfer_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
        ]);

        // A retried submit of a receipt already recorded must succeed quietly even though the
        // transfer may since have become fully received.
        $already = StockTransferReceipt::query()->where('stock_transfer_id', $stockTransfer->id)->where('idempotency_key', $data['idempotency_key'])->exists();
        $already
            ? abort_unless($request->user()->can('inventory.transfer') && $request->user()->can('view', $stockTransfer), 403)
            : Gate::authorize('receive', $stockTransfer);

        $this->transfers->receive($stockTransfer, $request->user(), $data);

        return back()->with('success', $already ? 'This receipt was already recorded.' : 'Receipt recorded.');
    }

    public function cancel(Request $request, Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('cancel', $stockTransfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->transfers->cancel($stockTransfer, $request->user(), $reason);

        return back()->with('success', 'Transfer cancelled; the goods are back in the source store.');
    }

    public function closeShort(Request $request, Project $project, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('closeShort', $stockTransfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->transfers->closeShort($stockTransfer, $request->user(), $reason);

        return back()->with('success', 'Transfer closed; the undelivered quantity was returned to the source store.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], [
            'from_warehouse_id' => 'source store',
            'to_warehouse_id' => 'destination store',
            'items.*.material_id' => 'item',
            'items.*.quantity' => 'quantity',
        ]);
    }

    private function form(Project $project, ?StockTransfer $transfer): Response
    {
        return Inertia::render('Inventory/Transfers/Form', [
            'project' => ProjectHeader::for($project),
            'transfer' => $transfer ? [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                ...$transfer->only(['from_warehouse_id', 'to_warehouse_id', 'vehicle_no', 'remarks']),
                'transfer_date' => $transfer->transfer_date?->toDateString(),
                'items' => $transfer->items()->get()->map(fn (StockTransferItem $i) => [
                    ...$i->only(['material_id', 'remarks']),
                    'quantity' => $i->quantity,
                ])->all(),
            ] : null,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => InventoryPresenter::materialOptions(),
            ],
            'stock' => InventoryPresenter::stockMap($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'transfer_date' => $transfer->transfer_date?->toDateString(),
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, StockTransfer $transfer): array
    {
        $draft = $transfer->isEditable();

        return [
            'update' => $draft && $user->can('update', $transfer),
            'delete' => $draft && $user->can('delete', $transfer),
            'dispatch' => $draft && $user->can('dispatch', $transfer),
            'receive' => $transfer->status->isInTransit() && $user->can('receive', $transfer),
            'cancel' => $transfer->status === StockTransferStatus::Dispatched && $user->can('cancel', $transfer),
            'close_short' => $transfer->status === StockTransferStatus::PartiallyReceived && $user->can('closeShort', $transfer),
        ];
    }
}

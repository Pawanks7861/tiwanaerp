<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\AdjustmentReason;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Support\Inventory\InventoryScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $adjustments,
        private readonly InventoryScope $scope,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [StockAdjustment::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'reason' => ['nullable', Rule::enum(AdjustmentReason::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->stockAdjustments()
            ->with('warehouse:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['reason'] ?? null, fn ($q, $reason) => $q->where('reason', $reason))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('adjustment_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Adjustments/Index', [
            'project' => ProjectHeader::for($project),
            'adjustments' => $page->through(fn (StockAdjustment $a) => [
                ...$this->header($a),
                'warehouse' => $a->warehouse?->name,
                'items_count' => $a->items_count,
            ]),
            'filters' => $filters,
            'statuses' => InventoryDocumentStatus::options(),
            'reasons' => AdjustmentReason::options(),
            'can' => ['create' => $request->user()->can('create', [StockAdjustment::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [StockAdjustment::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [StockAdjustment::class, $project]);

        $adjustment = $this->adjustments->create($project, $this->validated($request));

        return redirect()->route('projects.stock-adjustments.show', [$project, $adjustment])->with('success', "Adjustment {$adjustment->adjustment_number} saved as draft.");
    }

    public function show(Request $request, Project $project, StockAdjustment $stockAdjustment): Response
    {
        Gate::authorize('view', $stockAdjustment);

        $user = $request->user();
        $adjustment = $stockAdjustment->load(['warehouse:id,code,name', 'submitter:id,name', 'approver:id,name', 'canceller:id,name', 'creator:id,name']);
        $valuation = InventoryPresenter::seesValuation($user);

        return Inertia::render('Inventory/Adjustments/Show', [
            'project' => ProjectHeader::for($project),
            'adjustment' => [
                ...$this->header($adjustment),
                ...$adjustment->only(['remarks', 'rejection_reason', 'cancellation_reason']),
                'warehouse' => $adjustment->warehouse?->only(['id', 'code', 'name']),
                'created_by' => $adjustment->creator?->name,
                'submitted_by' => $adjustment->submitter?->name,
                'submitted_at' => $adjustment->submitted_at?->toIso8601String(),
                'approved_by' => $adjustment->approver?->name,
                'approved_at' => $adjustment->approved_at?->toIso8601String(),
                'cancelled_by' => $adjustment->canceller?->name,
                'cancelled_at' => $adjustment->cancelled_at?->toIso8601String(),
            ],
            'items' => $adjustment->items()->with(['material:id,code,name', 'unit:id,symbol'])->get()->map(fn (StockAdjustmentItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                ...$i->only(['system_qty', 'physical_qty', 'difference', 'remarks']),
                ...($valuation ? ['unit_cost' => $i->unit_cost, 'value' => $i->value] : []),
            ])->all(),
            'attachments' => ProcurementPresenter::attachments($adjustment),
            'can' => [...$this->abilities($user, $adjustment), 'attach' => $adjustment->isEditable() && $user->can('update', $adjustment), 'view_valuation' => $valuation],
        ]);
    }

    public function edit(Project $project, StockAdjustment $stockAdjustment): Response
    {
        Gate::authorize('update', $stockAdjustment);

        return $this->form($project, $stockAdjustment);
    }

    public function update(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('update', $stockAdjustment);

        $this->adjustments->update($stockAdjustment, $this->validated($request));

        return redirect()->route('projects.stock-adjustments.show', [$project, $stockAdjustment])->with('success', 'Adjustment updated; book quantities were refreshed.');
    }

    public function destroy(Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('delete', $stockAdjustment);

        $this->adjustments->delete($stockAdjustment);

        return redirect()->route('projects.stock-adjustments.index', $project)->with('success', "Adjustment {$stockAdjustment->adjustment_number} deleted.");
    }

    public function submit(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('submit', $stockAdjustment);

        $this->adjustments->submit($stockAdjustment, $request->user());

        return back()->with('success', 'Adjustment submitted for approval.');
    }

    public function approve(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        if ($stockAdjustment->status !== InventoryDocumentStatus::Approved) {
            Gate::authorize('approve', $stockAdjustment);
        } else {
            abort_unless($request->user()->can('inventory.approve_adjustment') && $request->user()->can('view', $stockAdjustment), 403);
        }

        $this->adjustments->approve($stockAdjustment, $request->user());

        return back()->with('success', 'Adjustment approved and posted to stock.');
    }

    public function reject(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('reject', $stockAdjustment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->adjustments->reject($stockAdjustment, $request->user(), $reason);

        return back()->with('success', 'Adjustment rejected.');
    }

    public function cancel(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('cancel', $stockAdjustment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->adjustments->cancel($stockAdjustment, $request->user(), $reason);

        return back()->with('success', 'Adjustment cancelled; its postings were reversed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.physical_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], [
            'warehouse_id' => 'store',
            'remarks' => 'explanation',
            'items.*.material_id' => 'item',
            'items.*.physical_qty' => 'physical quantity',
            'items.*.unit_cost' => 'unit cost',
        ]);
    }

    private function form(Project $project, ?StockAdjustment $adjustment): Response
    {
        return Inertia::render('Inventory/Adjustments/Form', [
            'project' => ProjectHeader::for($project),
            'adjustment' => $adjustment ? [
                'id' => $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                ...$adjustment->only(['warehouse_id', 'remarks']),
                'reason' => $adjustment->reason->value,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'items' => $adjustment->items()->get()->map(fn (StockAdjustmentItem $i) => [
                    ...$i->only(['material_id', 'remarks']),
                    'physical_qty' => $i->physical_qty,
                    'unit_cost' => $i->unit_cost,
                ])->all(),
            ] : null,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => InventoryPresenter::materialOptions(),
                'reasons' => AdjustmentReason::options(),
            ],
            'stock' => InventoryPresenter::stockMap($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(StockAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'adjustment_number' => $adjustment->adjustment_number,
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'reason' => $adjustment->reason->value,
            'reason_label' => $adjustment->reason->label(),
            'status' => $adjustment->status->value,
            'status_label' => $adjustment->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, StockAdjustment $adjustment): array
    {
        $editable = $adjustment->isEditable();
        $submitted = $adjustment->status === InventoryDocumentStatus::Submitted;
        $notSubmitter = (int) $adjustment->submitted_by !== $user->id;

        return [
            'update' => $editable && $user->can('update', $adjustment),
            'delete' => $editable && $user->can('delete', $adjustment),
            'submit' => $editable && $user->can('submit', $adjustment),
            'approve' => $submitted && $notSubmitter && $user->can('approve', $adjustment),
            'reject' => $submitted && $notSubmitter && $user->can('reject', $adjustment),
            'cancel' => $adjustment->status === InventoryDocumentStatus::Approved && $user->can('cancel', $adjustment),
        ];
    }
}

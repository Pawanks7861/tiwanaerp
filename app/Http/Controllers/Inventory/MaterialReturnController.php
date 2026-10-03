<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\MaterialReturnItem;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\MaterialReturnService;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialReturnController extends Controller
{
    public function __construct(
        private readonly MaterialReturnService $returns,
        private readonly InventoryScope $scope,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [MaterialReturn::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'type' => ['nullable', Rule::enum(MaterialReturnType::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->materialReturns()
            ->with(['warehouse:id,name', 'vendor:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('return_type', $type))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('return_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Returns/Index', [
            'project' => ProjectHeader::for($project),
            'returns' => $page->through(fn (MaterialReturn $r) => [
                ...$this->header($r),
                'warehouse' => $r->warehouse?->name,
                'vendor' => $r->vendor?->name,
                'items_count' => $r->items_count,
            ]),
            'filters' => $filters,
            'statuses' => InventoryDocumentStatus::options(),
            'types' => MaterialReturnType::options(),
            'can' => ['create' => $request->user()->can('create', [MaterialReturn::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [MaterialReturn::class, $project]);
        $type = MaterialReturnType::tryFrom((string) $request->query('type')) ?? MaterialReturnType::SiteToStore;

        return $this->form($project, $type, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [MaterialReturn::class, $project]);
        $type = MaterialReturnType::from($request->validate(['return_type' => ['required', Rule::enum(MaterialReturnType::class)]])['return_type']);

        $return = $this->returns->create($project, $type, $this->validated($request, $type));

        return redirect()->route('projects.material-returns.show', [$project, $return])->with('success', "Return {$return->return_number} saved as draft.");
    }

    public function show(Request $request, Project $project, MaterialReturn $materialReturn): Response
    {
        Gate::authorize('view', $materialReturn);

        $user = $request->user();
        $return = $materialReturn->load(['warehouse:id,code,name', 'vendor:id,code,name', 'grn:id,grn_number,project_id', 'approver:id,name', 'canceller:id,name', 'creator:id,name']);
        $valuation = InventoryPresenter::seesValuation($user);
        $items = $return->items()->with(['material:id,code,name', 'unit:id,symbol', 'issueItem.issue:id,issue_number', 'grnItem:id,grn_id'])->get();

        return Inertia::render('Inventory/Returns/Show', [
            'project' => ProjectHeader::for($project),
            'return' => [
                ...$this->header($return),
                ...$return->only(['reason', 'remarks', 'cancellation_reason']),
                'warehouse' => $return->warehouse?->only(['id', 'code', 'name']),
                'vendor' => $return->vendor?->only(['id', 'code', 'name']),
                'grn' => $return->grn ? ['number' => $return->grn->grn_number, 'url' => $user->can('grn.view') ? route('projects.grns.show', [$project, $return->grn->id]) : null] : null,
                'created_by' => $return->creator?->name,
                'approved_by' => $return->approver?->name,
                'approved_at' => $return->approved_at?->toIso8601String(),
                'cancelled_by' => $return->canceller?->name,
                'cancelled_at' => $return->cancelled_at?->toIso8601String(),
                ...($valuation ? ['total' => $items->every(fn (MaterialReturnItem $i) => $i->value !== null) ? Decimal::sum($items->pluck('value')->all())->toMoney() : null] : []),
            ],
            'items' => $items->map(fn (MaterialReturnItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'issue_number' => $i->issueItem?->issue?->issue_number,
                'remarks' => $i->remarks,
                ...($valuation ? ['unit_cost' => $i->unit_cost, 'value' => $i->value] : []),
            ])->all(),
            'approval' => ProcurementPresenter::approval($return, $user),
            'attachments' => ProcurementPresenter::attachments($return),
            'can' => [...$this->abilities($user, $return), 'attach' => $return->isEditable() && $user->can('update', $return), 'view_valuation' => $valuation],
        ]);
    }

    public function edit(Project $project, MaterialReturn $materialReturn): Response
    {
        Gate::authorize('update', $materialReturn);

        return $this->form($project, $materialReturn->return_type, $materialReturn);
    }

    public function update(Request $request, Project $project, MaterialReturn $materialReturn): RedirectResponse
    {
        Gate::authorize('update', $materialReturn);

        $this->returns->update($materialReturn, $this->validated($request, $materialReturn->return_type));

        return redirect()->route('projects.material-returns.show', [$project, $materialReturn])->with('success', 'Return updated.');
    }

    public function destroy(Project $project, MaterialReturn $materialReturn): RedirectResponse
    {
        Gate::authorize('delete', $materialReturn);

        $this->returns->delete($materialReturn);

        return redirect()->route('projects.material-returns.index', $project)->with('success', "Return {$materialReturn->return_number} deleted.");
    }

    public function submit(Request $request, Project $project, MaterialReturn $materialReturn): RedirectResponse
    {
        Gate::authorize('submit', $materialReturn);

        $this->returns->submit($materialReturn, $request->user());

        return back()->with('success', 'Return submitted for approval.');
    }

    public function cancel(Request $request, Project $project, MaterialReturn $materialReturn): RedirectResponse
    {
        Gate::authorize('cancel', $materialReturn);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->returns->cancel($materialReturn, $request->user(), $reason);

        return back()->with('success', 'Return cancelled; its postings were reversed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, MaterialReturnType $type): array
    {
        $vendor = $type === MaterialReturnType::ToVendor;

        return $request->validate([
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['required', 'integer'],
            'vendor_id' => [$vendor ? 'required_without:grn_id' : 'prohibited', 'nullable', 'integer'],
            'grn_id' => [$vendor ? 'nullable' : 'prohibited', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_issue_item_id' => [$vendor ? 'prohibited' : 'required', 'nullable', 'integer'],
            'items.*.grn_item_id' => [$vendor ? 'nullable' : 'prohibited', 'nullable', 'integer'],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], [
            'warehouse_id' => 'store',
            'vendor_id' => 'vendor',
            'items.*.material_issue_item_id' => 'issue line',
            'items.*.quantity' => 'quantity',
        ]);
    }

    private function form(Project $project, MaterialReturnType $type, ?MaterialReturn $return): Response
    {
        return Inertia::render('Inventory/Returns/Form', [
            'project' => ProjectHeader::for($project),
            'type' => $type->value,
            'type_label' => $type->label(),
            'return' => $return ? [
                'id' => $return->id,
                'return_number' => $return->return_number,
                ...$return->only(['warehouse_id', 'vendor_id', 'grn_id', 'reason', 'remarks']),
                'return_date' => $return->return_date?->toDateString(),
                'items' => $return->items()->get()->map(fn (MaterialReturnItem $i) => [
                    ...$i->only(['material_issue_item_id', 'grn_item_id', 'material_id', 'remarks']),
                    'quantity' => $i->quantity,
                ])->all(),
            ] : null,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => $type === MaterialReturnType::ToVendor ? InventoryPresenter::materialOptions() : [],
                'issue_lines' => $type === MaterialReturnType::SiteToStore ? $this->returns->returnableIssueLines($project, $return) : [],
                'grns' => $type === MaterialReturnType::ToVendor ? $this->returns->returnableGrns($project, $return) : [],
                'vendors' => $type === MaterialReturnType::ToVendor
                    ? Vendor::query()->active()->orderBy('name')->get(['id', 'code', 'name'])->map(fn (Vendor $v) => ['value' => $v->id, 'label' => $v->name, 'description' => $v->code])->all()
                    : [],
            ],
            'stock' => InventoryPresenter::stockMap($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(MaterialReturn $return): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'return_type' => $return->return_type->value,
            'type_label' => $return->return_type->label(),
            'return_date' => $return->return_date?->toDateString(),
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, MaterialReturn $return): array
    {
        $editable = $return->isEditable();

        return [
            'update' => $editable && $user->can('update', $return),
            'delete' => $editable && $user->can('delete', $return),
            'submit' => $editable && $user->can('submit', $return),
            'cancel' => $return->status === InventoryDocumentStatus::Approved && $user->can('cancel', $return),
        ];
    }
}

<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Rules\ExistsInCompany;
use App\Services\Inventory\MaterialIssueService;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialIssueController extends Controller
{
    public function __construct(
        private readonly MaterialIssueService $issues,
        private readonly InventoryScope $scope,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [MaterialIssue::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->materialIssues()
            ->with(['warehouse:id,name', 'subcontractor:id,name', 'issuedToUser:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('issue_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('issued_to_name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Issues/Index', [
            'project' => ProjectHeader::for($project),
            'issues' => $page->through(fn (MaterialIssue $issue) => [
                ...$this->header($issue),
                'warehouse' => $issue->warehouse?->name,
                'issued_to' => $this->issuedTo($issue),
                'items_count' => $issue->items_count,
            ]),
            'filters' => $filters,
            'statuses' => InventoryDocumentStatus::options(),
            'can' => ['create' => $user->can('create', [MaterialIssue::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [MaterialIssue::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [MaterialIssue::class, $project]);

        $issue = $this->issues->create($project, $this->validated($request, $project));

        return redirect()->route('projects.material-issues.show', [$project, $issue])->with('success', "Issue {$issue->issue_number} saved as draft.");
    }

    public function show(Request $request, Project $project, MaterialIssue $materialIssue): Response
    {
        Gate::authorize('view', $materialIssue);

        $user = $request->user();
        $issue = $materialIssue->load(['warehouse:id,code,name,project_id', 'subcontractor:id,name', 'issuedToUser:id,name', 'approver:id,name', 'canceller:id,name', 'creator:id,name']);
        $valuation = InventoryPresenter::seesValuation($user);
        $items = $issue->items()->with(['material:id,code,name', 'unit:id,symbol', 'boqItem:id,item_code,name,line_uid', 'task:id,wbs_code,name'])->get();

        return Inertia::render('Inventory/Issues/Show', [
            'project' => ProjectHeader::for($project),
            'issue' => [
                ...$this->header($issue),
                ...$issue->only(['purpose', 'remarks', 'cancellation_reason']),
                'warehouse' => $issue->warehouse?->only(['id', 'code', 'name']),
                'issued_to' => $this->issuedTo($issue),
                'created_by' => $issue->creator?->name,
                'approved_by' => $issue->approver?->name,
                'approved_at' => $issue->approved_at?->toIso8601String(),
                'cancelled_by' => $issue->canceller?->name,
                'cancelled_at' => $issue->cancelled_at?->toIso8601String(),
                ...($valuation ? ['total' => $items->every(fn (MaterialIssueItem $i) => $i->amount !== null) ? Decimal::sum($items->pluck('amount')->all())->toMoney() : null] : []),
            ],
            'items' => $items->map(fn (MaterialIssueItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
                'boq_item' => $i->boqItem ? trim($i->boqItem->item_code.' '.$i->boqItem->name) : null,
                'boq_line_uid' => $i->boqItem?->line_uid,
                'task' => $i->task ? trim($i->task->wbs_code.' '.$i->task->name) : null,
                'remarks' => $i->remarks,
                ...($valuation ? ['unit_cost' => $i->unit_cost, 'amount' => $i->amount] : []),
            ])->all(),
            'approval' => ProcurementPresenter::approval($issue, $user),
            'attachments' => ProcurementPresenter::attachments($issue),
            'can' => [...$this->abilities($user, $issue), 'attach' => $issue->isEditable() && $user->can('update', $issue), 'view_valuation' => $valuation],
        ]);
    }

    public function edit(Project $project, MaterialIssue $materialIssue): Response
    {
        Gate::authorize('update', $materialIssue);

        return $this->form($project, $materialIssue);
    }

    public function update(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        Gate::authorize('update', $materialIssue);

        $this->issues->update($materialIssue, $this->validated($request, $project));

        return redirect()->route('projects.material-issues.show', [$project, $materialIssue])->with('success', 'Issue updated.');
    }

    public function destroy(Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        Gate::authorize('delete', $materialIssue);

        $this->issues->delete($materialIssue);

        return redirect()->route('projects.material-issues.index', $project)->with('success', "Issue {$materialIssue->issue_number} deleted.");
    }

    public function submit(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        Gate::authorize('submit', $materialIssue);

        $this->issues->submit($materialIssue, $request->user());

        return back()->with('success', 'Issue submitted for approval.');
    }

    public function cancel(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        Gate::authorize('cancel', $materialIssue);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->issues->cancel($materialIssue, $request->user(), $reason);

        return back()->with('success', 'Issue cancelled; the stock and cost postings were reversed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'issued_to_user_id' => ['nullable', 'integer', Rule::exists('project_users', 'user_id')->where('project_id', $project->id)->where('is_active', true)],
            'subcontractor_id' => ['nullable', new ExistsInCompany(Subcontractor::class, activeOnly: true)],
            'issued_to_name' => ['nullable', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], [
            'warehouse_id' => 'store',
            'issued_to_user_id' => 'team member',
            'items.*.material_id' => 'item',
            'items.*.quantity' => 'quantity',
        ]);
    }

    private function form(Project $project, ?MaterialIssue $issue): Response
    {
        return Inertia::render('Inventory/Issues/Form', [
            'project' => ProjectHeader::for($project),
            'issue' => $issue ? [
                'id' => $issue->id,
                'issue_number' => $issue->issue_number,
                ...$issue->only(['warehouse_id', 'issued_to_user_id', 'subcontractor_id', 'issued_to_name', 'purpose', 'remarks']),
                'issue_date' => $issue->issue_date?->toDateString(),
                'items' => $issue->items()->get()->map(fn (MaterialIssueItem $i) => [
                    ...$i->only(['material_id', 'boq_item_id', 'task_id', 'remarks']),
                    'quantity' => $i->quantity,
                ])->all(),
            ] : null,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => InventoryPresenter::materialOptions(),
                'boq_items' => ProcurementPresenter::boqItemOptions($project),
                'tasks' => ProcurementPresenter::taskOptions($project),
                'members' => $project->users()->wherePivot('is_active', true)->orderBy('name')->get(['users.id', 'users.name'])
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
                'subcontractors' => Subcontractor::query()->active()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Subcontractor $s) => ['value' => $s->id, 'label' => $s->name])->all(),
            ],
            'stock' => InventoryPresenter::stockMap($project),
            'today' => now()->toDateString(),
        ]);
    }

    private function issuedTo(MaterialIssue $issue): ?string
    {
        return $issue->issuedToUser?->name ?? $issue->subcontractor?->name ?? $issue->issued_to_name;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(MaterialIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'issue_number' => $issue->issue_number,
            'issue_date' => $issue->issue_date?->toDateString(),
            'status' => $issue->status->value,
            'status_label' => $issue->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, MaterialIssue $issue): array
    {
        $editable = $issue->isEditable();
        $posted = $issue->status === InventoryDocumentStatus::Approved;

        return [
            'update' => $editable && $user->can('update', $issue),
            'delete' => $editable && $user->can('delete', $issue),
            'submit' => $editable && $user->can('submit', $issue),
            'cancel' => $posted && $user->can('cancel', $issue),
        ];
    }
}

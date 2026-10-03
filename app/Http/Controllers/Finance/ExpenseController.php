<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Finance\Expense;
use App\Models\Finance\PettyCashAccount;
use App\Models\Masters\ExpenseCategory;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Expense::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ExpenseStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->expenses()
            ->with(['category:id,name', 'vendor:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('expense_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('payee_name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('expense_date')->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Finance/Expenses/Index', [
            'project' => ProjectHeader::for($project),
            'expenses' => $page->through(fn (Expense $e) => $this->header($e)),
            'filters' => $filters,
            'statuses' => ExpenseStatus::options(),
            'can' => ['create' => $request->user()->can('create', [Expense::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [Expense::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Expense::class, $project]);

        $expense = $this->expenses->create($project, $this->validated($request), $request->user());

        return redirect()->route('projects.expenses.show', [$project, $expense])->with('success', "Expense {$expense->expense_number} saved as draft.");
    }

    public function show(Request $request, Project $project, Expense $expense): Response
    {
        Gate::authorize('view', $expense);
        $user = $request->user();
        $expense->load(['category:id,name', 'vendor:id,name,gstin', 'pettyCashAccount:id,name', 'task:id,wbs_code,name', 'approver:id,name', 'payer:id,name', 'reverser:id,name', 'creator:id,name']);

        return Inertia::render('Finance/Expenses/Show', [
            'project' => ProjectHeader::for($project),
            'expense' => [
                ...$this->header($expense),
                ...$expense->only(['amount', 'tax_amount', 'reference_no', 'description', 'revision', 'payment_reference', 'reversal_reason']),
                'cost_head_label' => $expense->cost_head->label(),
                'vendor' => $expense->vendor?->only(['id', 'name', 'gstin']),
                'petty_cash_account' => $expense->pettyCashAccount?->name,
                'task' => $expense->task ? trim($expense->task->wbs_code.' '.$expense->task->name) : null,
                'created_by' => $expense->creator?->name,
                'approved_by' => $expense->approver?->name,
                'approved_at' => $expense->approved_at?->toIso8601String(),
                'paid_by' => $expense->payer?->name,
                'paid_on' => $expense->paid_on?->toDateString(),
                'reversed_by' => $expense->reverser?->name,
                'reversed_at' => $expense->reversed_at?->toIso8601String(),
            ],
            'approval' => ProcurementPresenter::approval($expense, $user),
            'attachments' => ProcurementPresenter::attachments($expense),
            'can' => $this->abilities($user, $expense),
            'today' => now()->toDateString(),
        ]);
    }

    public function edit(Project $project, Expense $expense): Response
    {
        Gate::authorize('update', $expense);

        return $this->form($project, $expense);
    }

    public function update(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        Gate::authorize('update', $expense);

        $this->expenses->update($expense, $this->validated($request), $request->user());

        return redirect()->route('projects.expenses.show', [$project, $expense])->with('success', 'Expense updated.');
    }

    public function destroy(Project $project, Expense $expense): RedirectResponse
    {
        Gate::authorize('delete', $expense);

        $this->expenses->delete($expense);

        return redirect()->route('projects.expenses.index', $project)->with('success', "Expense {$expense->expense_number} deleted.");
    }

    public function submit(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        Gate::authorize('submit', $expense);

        $this->expenses->submit($expense, $request->user());

        return back()->with('success', 'Expense submitted for approval.');
    }

    public function markPaid(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        Gate::authorize('markPaid', $expense);

        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->expenses->markPaid($expense, $request->user(), $data);

        return back()->with('success', 'Expense marked paid. No cost was posted (it was posted at approval).');
    }

    public function reverse(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        Gate::authorize('reverse', $expense);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->expenses->reverse($expense, $request->user(), $reason);

        return back()->with('success', 'Expense reversed: its cost (and any petty cash drain) was reversed and it is a draft again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'expense_category_id' => ['required', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'petty_cash_account_id' => ['nullable', 'integer', 'required_if:payment_mode,petty_cash'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:1000'],
            'task_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
        ], [], ['expense_category_id' => 'category', 'petty_cash_account_id' => 'petty cash account']);
    }

    private function form(Project $project, ?Expense $expense): Response
    {
        return Inertia::render('Finance/Expenses/Form', [
            'project' => ProjectHeader::for($project),
            'expense' => $expense ? [
                'id' => $expense->id,
                'expense_number' => $expense->expense_number,
                ...$expense->only(['expense_category_id', 'vendor_id', 'payee_name', 'amount', 'tax_amount', 'petty_cash_account_id', 'reference_no', 'description', 'task_id', 'boq_item_id']),
                'payment_mode' => $expense->payment_mode->value,
                'expense_date' => $expense->expense_date?->toDateString(),
            ] : null,
            'categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cost_head'])
                ->map(fn (ExpenseCategory $c) => ['value' => $c->id, 'label' => $c->name, 'description' => 'Cost head: '.$c->cost_head->label()])->all(),
            'vendors' => ProcurementPresenter::vendorOptions(),
            'modes' => PaymentMode::options(),
            'pettyCashAccounts' => PettyCashAccount::query()->where('project_id', $project->id)->where('is_active', true)
                ->with('holder:id,name')->orderBy('name')->get()
                ->map(fn (PettyCashAccount $a) => ['value' => $a->id, 'label' => $a->name, 'description' => 'Holder '.$a->holder?->name.' · balance '.$a->balance()->toMoney()])->all(),
            'tasks' => ProcurementPresenter::taskOptions($project),
            'boqItems' => ProcurementPresenter::boqItemOptions($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_number' => $expense->expense_number,
            'expense_date' => $expense->expense_date?->toDateString(),
            'status' => $expense->status->value,
            'status_label' => $expense->status->label(),
            'category' => $expense->category?->name,
            'payee' => $expense->vendor?->name ?? $expense->payee_name,
            'payment_mode' => $expense->payment_mode->value,
            'payment_mode_label' => $expense->payment_mode->label(),
            'total_amount' => $expense->total_amount,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, Expense $expense): array
    {
        $editable = $expense->isEditable();

        return [
            'update' => $editable && $user->can('update', $expense),
            'delete' => $editable && $expense->revision === 0 && $user->can('delete', $expense),
            'submit' => $editable && $user->can('submit', $expense),
            'markPaid' => $expense->status === ExpenseStatus::Approved && ! $expense->isPettyCash() && $user->can('markPaid', $expense),
            'reverse' => ($expense->status === ExpenseStatus::Approved || ($expense->status === ExpenseStatus::Paid && $expense->isPettyCash()))
                && $user->can('reverse', $expense),
            'attach' => $editable && $user->can('update', $expense),
        ];
    }
}

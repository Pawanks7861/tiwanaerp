<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Finance\Expense;
use App\Models\Projects\Project;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExpenseApiController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Expense::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ExpenseStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $page = $project->expenses()
            ->with(['category:id,name', 'vendor:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('expense_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('expense_date')->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ExpenseResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Expense::class, $project]);
        $expense = $this->expenses->create($project, $this->validated($request), $request->user());

        return (new ExpenseResource($this->detail($expense)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Expense $expense): ExpenseResource
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('view', $expense);

        return new ExpenseResource($this->detail($expense));
    }

    public function update(Request $request, Project $project, Expense $expense): ExpenseResource
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('update', $expense);
        $updated = $this->expenses->update($expense, $this->validated($request), $request->user());

        return new ExpenseResource($this->detail($updated));
    }

    public function destroy(Project $project, Expense $expense): JsonResponse
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('delete', $expense);
        $this->expenses->delete($expense);

        return response()->json(['message' => 'Expense deleted.']);
    }

    public function submit(Request $request, Project $project, Expense $expense): ExpenseResource
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('submit', $expense);
        $this->expenses->submit($expense, $request->user());

        return new ExpenseResource($this->detail($expense->fresh()));
    }

    public function markPaid(Request $request, Project $project, Expense $expense): ExpenseResource
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('markPaid', $expense);
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->expenses->markPaid($expense, $request->user(), $data);

        return new ExpenseResource($this->detail($expense->fresh()));
    }

    public function reverse(Request $request, Project $project, Expense $expense): ExpenseResource
    {
        $this->assertOnProject($project, $expense);
        Gate::authorize('reverse', $expense);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->expenses->reverse($expense, $request->user(), $reason);

        return new ExpenseResource($this->detail($expense->fresh()));
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

    private function detail(Expense $expense): Expense
    {
        return $expense->load([
            'category:id,name',
            'vendor:id,name',
            'latestApprovalRequest.submitter:id,name',
            'attachments.uploader:id,name',
        ]);
    }

    private function assertOnProject(Project $project, Expense $expense): void
    {
        abort_unless((int) $expense->project_id === (int) $project->id, 404);
    }
}

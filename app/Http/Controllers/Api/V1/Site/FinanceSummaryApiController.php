<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\VendorBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\VendorBill;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\CashFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinanceSummaryApiController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
        private readonly ApprovalService $approvals,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless(FinancePresenter::seesAnyFinance($user), 403);

        $canBilling = Gate::allows('viewAny', [ClientInvoice::class, $project]);
        $canPayments = Gate::allows('viewAny', [Payment::class, $project]);
        $canVendorBills = Gate::allows('viewAny', [VendorBill::class, $project]);
        $canExpenses = Gate::allows('viewAny', [Expense::class, $project]);
        $seesMoney = FinancePresenter::seesPayments($user);

        $entries = $canPayments
            ? $this->cashFlow->entries([$project->id], [])
            : collect();
        $totals = $seesMoney ? $this->cashFlow->totals($entries) : null;
        $outstanding = ($canBilling || $canPayments || $canVendorBills)
            ? $this->redactOutstanding($this->cashFlow->outstanding([$project->id]), $user, $canBilling, $canVendorBills)
            : null;

        return response()->json([
            'data' => [
                'cash_flow' => $canPayments ? [
                    'entry_count' => $entries->count(),
                    'totals' => $totals,
                ] : null,
                'outstanding' => $outstanding,
                'counts' => [
                    'client_ra_bills' => $canBilling ? $this->clientInvoiceCounts($project) : null,
                    'vendor_bills' => $canVendorBills ? $this->vendorBillCounts($project) : null,
                    'expenses' => $canExpenses ? $this->expenseCounts($project) : null,
                    'receipts_draft' => $canPayments ? Payment::query()->where('project_id', $project->id)
                        ->where('direction', PaymentDirection::Receipt)->where('status', PaymentStatus::Draft)->count() : null,
                    'payments_draft' => $canPayments ? Payment::query()->where('project_id', $project->id)
                        ->where('direction', PaymentDirection::Payment)->where('status', PaymentStatus::Draft)->count() : null,
                ],
                'pending_approvals' => $user->can('approvals.view')
                    ? $this->pendingFinanceApprovals($user, $project)
                    : null,
                'can' => [
                    'view_client_bills' => $canBilling,
                    'view_payments' => $canPayments,
                    'view_vendor_bills' => $canVendorBills,
                    'view_expenses' => $canExpenses,
                    'create_client_bill' => Gate::allows('create', [ClientInvoice::class, $project]),
                    'record_payment' => Gate::allows('create', [Payment::class, $project]),
                    'create_vendor_bill' => Gate::allows('create', [VendorBill::class, $project]),
                    'create_expense' => Gate::allows('create', [Expense::class, $project]),
                ],
            ],
        ]);
    }

    private function countByStatus($query): \Illuminate\Support\Collection
    {
        return (clone $query)->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');
    }

    /** @return array<string, int> */
    private function clientInvoiceCounts(Project $project): array
    {
        $rows = $this->countByStatus(ClientInvoice::query()->where('project_id', $project->id));
        $draft = (int) ($rows[ClientInvoiceStatus::Draft->value] ?? 0);
        $submitted = (int) ($rows[ClientInvoiceStatus::Submitted->value] ?? 0);
        $certified = (int) ($rows[ClientInvoiceStatus::Certified->value] ?? 0)
            + (int) ($rows[ClientInvoiceStatus::PartiallyPaid->value] ?? 0)
            + (int) ($rows[ClientInvoiceStatus::Paid->value] ?? 0);

        return ['total' => (int) $rows->sum(), 'draft' => $draft, 'submitted' => $submitted, 'certified' => $certified, 'open' => $draft + $submitted];
    }

    /** @return array<string, int> */
    private function vendorBillCounts(Project $project): array
    {
        $rows = $this->countByStatus(VendorBill::query()->where('project_id', $project->id));
        $draft = (int) ($rows[VendorBillStatus::Draft->value] ?? 0);
        $submitted = (int) ($rows[VendorBillStatus::Submitted->value] ?? 0);
        $approved = (int) ($rows[VendorBillStatus::Approved->value] ?? 0)
            + (int) ($rows[VendorBillStatus::PartiallyPaid->value] ?? 0)
            + (int) ($rows[VendorBillStatus::Paid->value] ?? 0);

        return ['total' => (int) $rows->sum(), 'draft' => $draft, 'submitted' => $submitted, 'approved' => $approved, 'open' => $draft + $submitted];
    }

    /** @return array<string, int> */
    private function expenseCounts(Project $project): array
    {
        $rows = $this->countByStatus(Expense::query()->where('project_id', $project->id));
        $draft = (int) ($rows[ExpenseStatus::Draft->value] ?? 0);
        $submitted = (int) ($rows[ExpenseStatus::Submitted->value] ?? 0);
        $approved = (int) ($rows[ExpenseStatus::Approved->value] ?? 0) + (int) ($rows[ExpenseStatus::Paid->value] ?? 0);

        return ['total' => (int) $rows->sum(), 'draft' => $draft, 'submitted' => $submitted, 'approved' => $approved, 'open' => $draft + $submitted];
    }

    /**
     * @param  array{receivables: list<array<string, mixed>>, payables: list<array<string, mixed>>, totals: array<string, string>}  $outstanding
     * @return array<string, mixed>
     */
    private function redactOutstanding(array $outstanding, $user, bool $canBilling, bool $canVendorBills): array
    {
        $seesBilling = FinancePresenter::seesBilling($user);
        $seesPay = FinancePresenter::seesPayments($user);

        return [
            'receivables' => $canBilling && $seesBilling
                ? $outstanding['receivables']
                : array_map(fn ($r) => $this->stripMoney($r), $outstanding['receivables']),
            'payables' => $canVendorBills && $seesPay
                ? array_values(array_filter($outstanding['payables'], fn ($r) => ($r['party_type'] ?? '') === 'Vendor'))
                : [],
            'totals' => [
                'receivable' => $canBilling && $seesBilling ? $outstanding['totals']['receivable'] : null,
                'payable' => $canVendorBills && $seesPay ? $outstanding['totals']['payable'] : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function stripMoney(array $row): array
    {
        unset($row['due'], $row['settled'], $row['outstanding']);

        return $row;
    }

    private function pendingFinanceApprovals($user, Project $project): int
    {
        return $this->approvals->pendingFor($user)
            ->filter(function ($row) use ($project) {
                $doc = $row->approvable;
                if ($doc === null || ! isset($doc->project_id) || (int) $doc->project_id !== (int) $project->id) {
                    return false;
                }

                return $doc instanceof ClientInvoice
                    || $doc instanceof VendorBill
                    || $doc instanceof Expense
                    || $doc instanceof \App\Models\Finance\RetentionRelease;
            })
            ->count();
    }
}

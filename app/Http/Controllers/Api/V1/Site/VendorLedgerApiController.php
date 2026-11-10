<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\VendorBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\VendorBill;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Services\Finance\CashFlowService;
use App\Services\Finance\PayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only vendor settlement view: cash movements involving the vendor and open vendor bills.
 */
class VendorLedgerApiController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
        private readonly PayableService $payables,
    ) {}

    public function ledger(Request $request, Project $project, Vendor $vendor): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('vendor_bills.view') || $request->user()->can('payments.view'), 403);

        $vendorName = mb_strtolower($vendor->name);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $filters['project_id'] = $project->id;
        $filters['party'] = $vendor->name;

        $entries = $this->cashFlow->entries([$project->id], $filters)
            ->filter(fn ($row) => isset($row['party']) && str_contains(mb_strtolower((string) $row['party']), $vendorName))
            ->values();
        $seesMoney = FinancePresenter::seesPayments($request->user()) || FinancePresenter::seesVendorBills($request->user());

        $bills = VendorBill::query()->where('project_id', $project->id)->where('vendor_id', $vendor->id)
            ->whereIn('status', VendorBillStatus::approvedStates())
            ->orderByDesc('id')->get();

        return response()->json([
            'data' => [
                'vendor' => $vendor->only(['id', 'code', 'name']),
                'entries' => $seesMoney
                    ? $entries->take(500)->values()
                    : $entries->map(fn ($row) => collect($row)->except(['inflow', 'outflow'])->all())->take(500)->values(),
                'totals' => $seesMoney ? $this->cashFlow->totals($entries) : null,
                'open_bills' => $bills->map(fn (VendorBill $b) => [
                    'id' => $b->id,
                    'bill_number' => $b->bill_number,
                    'vendor_invoice_no' => $b->vendor_invoice_no,
                    'net_payable' => $seesMoney ? $b->net_payable : null,
                    'outstanding' => $seesMoney ? $this->payables->outstanding($b)->toMoney() : null,
                ])->filter(fn ($row) => $row['outstanding'] === null || (float) $row['outstanding'] > 0)->values(),
            ],
        ]);
    }

    public function reconciliation(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('payments.view') || $request->user()->can('vendor_bills.view'), 403);
        Gate::authorize('view', $project);

        $outstanding = $this->cashFlow->outstanding([$project->id]);
        $seesMoney = FinancePresenter::seesPayments($request->user()) || FinancePresenter::seesVendorBills($request->user());
        $payables = collect($outstanding['payables'])->where('party_type', 'Vendor')->values();

        return response()->json([
            'data' => [
                'as_of' => now()->toDateString(),
                'payables' => $seesMoney
                    ? $payables
                    : $payables->map(fn ($row) => collect($row)->except(['due', 'settled', 'outstanding'])->all()),
                'total_payable' => $seesMoney ? $outstanding['totals']['payable'] : null,
            ],
        ]);
    }
}

<?php

use App\Enums\CostHead;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Quotation;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\TaxRate;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ClientInvoiceService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\PettyCashService;
use App\Services\Labour\LabourPaymentService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * One project with every kind of cash document:
 *   in:  receipt 20,000 (RA bill, net 38,199.78); petty cash return 800
 *   out: subcontractor payment 10,000; cash expense 1,313.03 (1,250.50 + GST 62.53);
 *        petty cash funding 5,000; labour batch 1,340 marked paid directly (Phase 6)
 * plus a 1,200 petty cash expense, which drains the float but is not a company cash movement.
 */
beforeEach(function () {
    $this->setUpResources();
    $this->cashier = $this->createMember($this->company, DefaultRoles::ACCOUNTANT);
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
    });
    $this->postProgress('8', now()->subDays(3)->toDateString());
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2'],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day'],
    ], now()->subDays(2)->toDateString());
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $this->subBill = $this->certifyBill($this->makeBill($order, ['35', '10']));

    $this->inCompany($this->company, function () {
        $approvals = app(ApprovalService::class);
        $today = now()->toDateString();

        $invoices = app(ClientInvoiceService::class);
        $this->invoice = $invoices->create($this->project, [
            'invoice_date' => $today, 'period_from' => now()->subDays(10)->toDateString(), 'period_to' => $today,
            'tax_rate_id' => TaxRate::query()->where('name', 'GST 18%')->value('id'),
            'retention_percent' => '5', 'tds_percent' => '2', 'other_deductions' => '100',
            'items' => [['boq_item_id' => $this->boqLine->id, 'current_qty' => '5']],
        ], $this->billing);
        $invoices->submit($this->invoice, $this->billing);
        $approvals->approve($approvals->approve($this->invoice->fresh()->pendingApprovalRequest(), $this->pm), $this->director);

        $payments = app(PaymentService::class);
        $this->actingAs($this->accountant);
        $receipt = $payments->create($this->project, ['party_type' => 'client', 'party_id' => $this->client->id, 'payment_date' => $today, 'mode' => 'bank_transfer',
            'amount' => '20000', 'allocations' => [['payable_id' => $this->invoice->id, 'amount' => '20000']]]);
        $payments->approve($receipt, $this->director);
        $paid = $payments->create($this->project, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'payment_date' => $today, 'mode' => 'cheque',
            'amount' => '10000', 'allocations' => [['payable_id' => $this->subBill->id, 'amount' => '10000']]]);
        $payments->approve($paid, $this->director);

        $expenses = app(ExpenseService::class);
        $transport = ExpenseCategory::query()->where('name', 'Transport')->value('id');
        $expense = $expenses->create($this->project, ['expense_category_id' => $transport, 'payee_name' => 'Ganesh Tempo', 'expense_date' => $today,
            'amount' => '1250.50', 'tax_amount' => '62.53', 'payment_mode' => 'cash', 'description' => 'Shifting'], $this->engineer);
        $expenses->submit($expense, $this->engineer);
        $approvals->approve($approvals->approve($expense->fresh()->pendingApprovalRequest(), $this->pm), $this->accountant);
        $expenses->markPaid($expense->fresh(), $this->accountant, ['paid_on' => $today, 'payment_reference' => 'CASH-1']);

        $petty = app(PettyCashService::class);
        $account = $petty->createAccount($this->project, ['name' => 'Site float', 'holder_user_id' => $this->cashier->id]);
        $petty->fund($account, $this->accountant, ['amount' => '5000', 'txn_date' => $today]);
        $pettyExpense = $expenses->create($this->project, ['expense_category_id' => $transport, 'payee_name' => 'Auto', 'expense_date' => $today,
            'amount' => '1200', 'payment_mode' => 'petty_cash', 'petty_cash_account_id' => $account->id, 'description' => 'Auto fares'], $this->cashier);
        $expenses->submit($pettyExpense, $this->cashier);
        $approvals->approve($approvals->approve($pettyExpense->fresh()->pendingApprovalRequest(), $this->pm), $this->accountant);
        $petty->returnCash($account->fresh(), $this->accountant, ['amount' => '800', 'txn_date' => $today]);

        $labour = app(LabourPaymentService::class);
        $batch = $labour->create($this->project, ['period_from' => now()->subDays(3)->toDateString(), 'period_to' => $today]);
        $labour->submit($batch, $this->accountant);
        $labour->approve($batch->fresh(), $this->pm);
        $labour->markPaid($batch->fresh(), $this->accountant, ['paid_on' => $today, 'payment_reference' => 'CASH-LAB']);
    });
});

test('cash flow lists only cash documents with exact totals', function () {
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.cash-flow', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/CashFlow/Index')
            ->has('entries', 6)
            ->where('totals.inflow', '20800.00')
            ->where('totals.outflow', '17653.03')
            ->where('totals.net', '3146.97')
            ->where('entries', fn ($entries) => collect($entries)->pluck('type')->sort()->values()->all() === [
                'expense', 'labour_direct', 'payment', 'petty_cash_funding', 'petty_cash_return', 'receipt',
            ]));
});

test('cash is not cost: the cost ledger and the cash flow differ and neither feeds the other', function () {
    // Cost: expense 1,250.50 (GST excluded) + petty expense 1,200 + labour 1,340 + subcontract 26,750.
    expect($this->netCost(CostHead::Other))->toBe('2450.50')
        ->and($this->netCost(CostHead::Labour))->toBe('1340.00')
        ->and($this->netCost(CostHead::Subcontract))->toBe('26750.00')
        ->and($this->costs()->pluck('source_type')->unique()->sort()->values()->all())
        ->toBe(['expense', 'labour_attendance', 'subcontractor_bill_item']);

    // Cash out is 17,653.03 — the petty spend and the unpaid part of the subcontract bill are not cash yet.
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.cash-flow', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.outflow', '17653.03')
            ->where('entries', fn ($entries) => collect($entries)->every(fn ($e) => $e['document'] !== 'EXP-PRJ001-0002')));
});

test('filters by type, mode, party and date', function () {
    $get = fn (array $filters) => $this->actingInCompany($this->accountant, $this->company)->get(route('projects.cash-flow', [$this->project, ...$filters]));

    $get(['type' => 'receipt'])->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.inflow', '20000.00'));
    $get(['mode' => 'cash'])->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.document', 'EXP-PRJ001-0001'));
    $get(['party' => 'shree'])->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.outflow', '10000.00'));
    $get(['from' => now()->addDay()->toDateString()])->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('totals.net', '0.00'));
    $get(['type' => 'unknown'])->assertSessionHasErrors('type');
});

test('outstanding receivables and payables are derived from documents and approved allocations', function () {
    $subDue = $this->inCompany($this->company, fn () => $this->subBill->fresh()->net_payable);

    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.cash-flow', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->has('outstanding.receivables', 1)
            ->where('outstanding.receivables.0.due', '38199.78')
            ->where('outstanding.receivables.0.settled', '20000.00')
            ->where('outstanding.receivables.0.outstanding', '18199.78')
            ->where('outstanding.totals.receivable', '18199.78')
            ->has('outstanding.payables', 1) // the labour batch is closed, the subcontract bill is open
            ->where('outstanding.payables.0.outstanding', Decimal::of($subDue)->minus('10000')->toMoney()));
});

test('cash flow needs payments.view and stays within visible projects and the tenant', function () {
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.cash-flow', $this->project))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->get(route('finance.cash-flow'))->assertForbidden();

    $this->actingInCompany($this->director, $this->company)->get(route('finance.cash-flow'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/CashFlow/Company')->has('entries', 6)->where('totals.net', '3146.97'));
    $this->actingInCompany($this->director, $this->company)->get(route('finance.cash-flow', ['project_id' => $this->otherProject->id]))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->has('outstanding.receivables', 0));

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('finance.cash-flow'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('outstanding.totals.receivable', '0.00'));
    $this->actingInCompany($outsider, $other)->get(route('finance.cash-flow', ['project_id' => $this->project->id]))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
    $this->actingInCompany($outsider, $other)->get(route('projects.cash-flow', $this->project))->assertNotFound();

    expect($this->inCompany($this->company, fn () => Payment::query()->count()))->toBe(2)
        ->and($this->inCompany($this->company, fn () => Expense::query()->count()))->toBe(2);
});

test('every finance and CRM screen renders for an admin', function () {
    $admin = $this->admin;
    $as = fn () => $this->actingInCompany($admin, $this->company);
    $today = now()->toDateString();
    [$gst18, $day] = $this->inCompany($this->company, fn () => [TaxRate::query()->where('name', 'GST 18%')->value('id'), $this->boqLine->unit_id]);

    $as()->post(route('projects.expenses.store', $this->project), [
        'expense_category_id' => $this->inCompany($this->company, fn () => ExpenseCategory::query()->where('name', 'Transport')->value('id')),
        'payee_name' => 'Draft payee', 'expense_date' => $today, 'amount' => '10', 'payment_mode' => 'cash', 'description' => 'Draft',
    ])->assertSessionHasNoErrors();
    $as()->post(route('projects.ra-bills.store', $this->project), [
        'invoice_date' => $today, 'period_from' => $today, 'period_to' => $today, 'tax_rate_id' => $gst18,
        'items' => [['boq_item_id' => $this->boqLine->id, 'current_qty' => '1']],
    ])->assertSessionHasNoErrors();
    $as()->post(route('projects.vendor-bills.store', $this->project), [
        'bill_type' => 'direct', 'vendor_id' => $this->hireVendor->id, 'vendor_invoice_no' => 'SMOKE-1', 'vendor_invoice_date' => $today, 'cost_head' => 'equipment',
        'items' => [['description' => 'Crane hire', 'unit_id' => $day, 'quantity' => '1', 'rate' => '100', 'tax_rate_id' => $gst18]],
    ])->assertSessionHasNoErrors();
    $as()->post(route('projects.payments.store', $this->project), [
        'party_type' => 'client', 'party_id' => $this->client->id, 'payment_date' => $today, 'mode' => 'cash', 'amount' => '100',
        'allocations' => [['payable_id' => $this->invoice->id, 'amount' => '100']],
    ])->assertSessionHasNoErrors();
    $as()->post(route('projects.retention.store', $this->project), [
        'releasable_type' => 'client_invoice', 'releasable_id' => $this->invoice->id, 'release_date' => $today, 'amount' => '100',
    ])->assertSessionHasNoErrors();
    $as()->post(route('crm.leads.store'), ['name' => 'Smoke Lead', 'mobile' => '9820000000', 'state_code' => '27'])->assertSessionHasNoErrors();

    [$expense, $invoice, $bill, $payment, $account, $lead] = $this->inCompany($this->company, fn () => [
        Expense::query()->latest('id')->first(), $this->project->clientInvoices()->latest('id')->first(),
        $this->project->vendorBills()->latest('id')->first(), Payment::query()->latest('id')->first(),
        $this->project->pettyCashAccounts()->first(), Lead::query()->latest('id')->first(),
    ]);
    $as()->post(route('crm.quotations.store'), [
        'lead_id' => $lead->id, 'quotation_date' => $today, 'valid_until' => now()->addDays(30)->toDateString(), 'title' => 'Smoke quote',
        'project_name' => 'Smoke Tower', 'place_of_supply_state' => '27',
        'items' => [['description' => 'Civil works', 'quantity' => '1', 'rate' => '1000', 'tax_rate_id' => $gst18]],
    ])->assertSessionHasNoErrors();
    $quotation = $this->inCompany($this->company, fn () => Quotation::query()->latest('id')->first());

    $screens = [
        'Finance/Expenses/Index' => route('projects.expenses.index', $this->project),
        'Finance/Expenses/Form' => [route('projects.expenses.create', $this->project), route('projects.expenses.edit', [$this->project, $expense])],
        'Finance/Expenses/Show' => route('projects.expenses.show', [$this->project, $expense]),
        'Finance/PettyCash/Index' => route('projects.petty-cash.index', $this->project),
        'Finance/PettyCash/Show' => route('projects.petty-cash.show', [$this->project, $account]),
        'Finance/ClientBills/Index' => route('projects.ra-bills.index', $this->project),
        'Finance/ClientBills/Form' => route('projects.ra-bills.edit', [$this->project, $invoice]),
        'Finance/ClientBills/Show' => [route('projects.ra-bills.show', [$this->project, $invoice]), route('projects.ra-bills.show', [$this->project, $this->invoice])],
        'Finance/VendorBills/Index' => route('projects.vendor-bills.index', $this->project),
        'Finance/VendorBills/Form' => [route('projects.vendor-bills.create', $this->project), route('projects.vendor-bills.edit', [$this->project, $bill])],
        'Finance/VendorBills/Show' => route('projects.vendor-bills.show', [$this->project, $bill]),
        'Finance/Payments/Index' => route('projects.payments.index', $this->project),
        'Finance/Payments/Form' => [route('projects.payments.create', $this->project), route('projects.payments.edit', [$this->project, $payment])],
        'Finance/Payments/Show' => route('projects.payments.show', [$this->project, $payment]),
        'Finance/Retention/Index' => route('projects.retention.index', $this->project),
        'Finance/CashFlow/Index' => route('projects.cash-flow', $this->project),
        'Finance/CashFlow/Company' => route('finance.cash-flow'),
        'Crm/Leads/Index' => route('crm.leads.index'),
        'Crm/Leads/Form' => [route('crm.leads.create'), route('crm.leads.edit', $lead)],
        'Crm/Leads/Show' => route('crm.leads.show', $lead),
        'Crm/Quotations/Index' => route('crm.quotations.index'),
        'Crm/Quotations/Form' => [route('crm.quotations.create'), route('crm.quotations.edit', $quotation)],
        'Crm/Quotations/Show' => route('crm.quotations.show', $quotation),
    ];

    foreach ($screens as $component => $urls) {
        foreach ((array) $urls as $url) {
            $as()->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }
});

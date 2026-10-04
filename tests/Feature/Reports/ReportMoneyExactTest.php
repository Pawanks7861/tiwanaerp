<?php

use App\Enums\CostHead;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\TaxRate;
use App\Models\Projects\ProjectUser;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\ProjectBudgetService;
use App\Services\Finance\ClientInvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\PettyCashService;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Labour\LabourPaymentService;
use App\Services\Projects\ProjectService;
use App\Services\SiteExecution\DprService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Exact Phase 9 money. Budget lines and ledger postings below are chosen so the arithmetic
 * is hand-checkable. Committed cost follows CommittedCostQuery: open PO/WO value, GST excluded,
 * and certified subcontract cost leaves the commitment (it is actual, not both).
 */
beforeEach(function () {
    $this->setUpResources();
});

function postHeads($test, array $amounts, string $date, $source = null): void
{
    $source ??= $test->project;
    $test->inCompany($test->company, function () use ($test, $amounts, $date, $source) {
        $ledger = app(ProjectCostLedgerService::class);
        foreach ($amounts as $head => $amount) {
            $ledger->post($source, $test->project->id, CostHead::from($head), Decimal::of($amount), $date, userId: $test->director->id);
        }
    });
}

function approveManualBudget($test, array $lines): void
{
    $test->inCompany($test->company, function () use ($test, $lines) {
        $budgets = app(ProjectBudgetService::class);
        foreach ($lines as $head => $amount) {
            $budgets->saveLine($test->project, ['cost_head' => $head, 'description' => $head, 'amount' => $amount]);
        }
        $budgets->approve($test->project->budgets()->first(), $test->director);
    });
}

function openReport($test, string $key, array $query = [], $user = null, $project = null)
{
    $user ??= $test->director;
    $url = $project ? route('reports.project', [$project, $key]) : route('reports.show', $key);

    return $test->actingInCompany($user, $test->company)->get($url.($query === [] ? '' : '?'.http_build_query($query)));
}

test('budget vs actual uses the approved budget and the cost ledger exactly', function () {
    approveManualBudget($this, [
        'material' => '100000', 'labour' => '40000', 'equipment' => '25000',
        'subcontract' => '80000', 'overhead' => '15000', 'other' => '5000',
    ]);
    postHeads($this, [
        'material' => '20000', 'labour' => '8000', 'equipment' => '4000',
        'subcontract' => '10000', 'overhead' => '3000', 'other' => '1000',
    ], now()->toDateString());

    // 265000 budget, 46000 actual, committed 0. Variance = budget − actual = 219000.
    // Utilisation = 46000 / 265000 × 100 = 17.36.
    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.budget', '265000.00')
            ->where('result.totals.actual', '46000.00')
            ->where('result.totals.committed', '0.00')
            ->where('result.totals.variance', '219000.00')
            ->where('result.totals.available', '219000.00')
            ->where('result.totals.utilization', '17.36')
            ->where('result.rows', fn ($rows) => collect($rows)->firstWhere('label', 'Material')['actual'] === '20000.00'
                && collect($rows)->firstWhere('label', 'Subcontract')['actual'] === '10000.00'));

    $material = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->where('cost_head', 'material')->where('is_reversal', false)->first());
    $this->inCompany($this->company, fn () => app(ProjectCostLedgerService::class)->reverse($material, 'void', $this->director->id));

    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.actual', '26000.00')
            ->where('result.totals.variance', '239000.00'));
});

test('cost by head totals equal the ledger and the heads add up to the grand total', function () {
    postHeads($this, [
        'material' => '20000.50', 'labour' => '8000', 'equipment' => '4000.25',
        'subcontract' => '10000', 'overhead' => '3000', 'other' => '999.25',
    ], now()->toDateString());

    openReport($this, 'cost-by-head', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->where('result.totals.net', '46000.00');
            $cards = collect($page->toArray()['props']['result']['cards'])->sum(fn ($card) => (float) $card['value']);
            expect(number_format($cards, 2, '.', ''))->toBe('46000.00');
            $rows = collect($page->toArray()['props']['result']['rows'])->sum(fn ($row) => (float) $row['net']);
            expect(number_format($rows, 2, '.', ''))->toBe('46000.00');
        });
});

test('open work order value is committed and certified cost is not counted twice', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    expect($order->subtotal)->toBe('61000.00');

    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.actual', '0.00')
            ->where('result.totals.committed', '61000.00'));

    $partial = $this->certifyBill($this->makeBill($order, ['35', '10']));
    expect($partial->gross_amount)->toBe('26750.00')->and($this->netCost(CostHead::Subcontract))->toBe('26750.00');

    // Open commitment = 61000 − 26750 posted = 34250. Actual is the ledger, not the commitment.
    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.actual', '26750.00')
            ->where('result.totals.committed', '34250.00'));

    $this->certifyBill($this->makeBill($order->fresh(), ['65', '10']));
    expect($this->netCost(CostHead::Subcontract))->toBe('61000.00');

    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.actual', '61000.00')
            ->where('result.totals.committed', '0.00'));
});

test('client receivables follow approved allocations, including cancellation', function () {
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
        $this->gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
    });
    $this->postProgress('8', now()->subDays(3)->toDateString());

    $bill = $this->inCompany($this->company, function () {
        $service = app(ClientInvoiceService::class);
        $invoice = $service->create($this->project, [
            'invoice_date' => now()->toDateString(),
            'period_from' => now()->subDays(10)->toDateString(),
            'period_to' => now()->toDateString(),
            'tax_rate_id' => $this->gst18,
            'retention_percent' => '5',
            'tds_percent' => '2',
            'advance_recovery' => '0',
            'other_deductions' => '100',
            'items' => [['boq_item_id' => $this->boqLine->id, 'current_qty' => '5']],
        ], $this->billing);
        $service->submit($invoice, $this->billing);
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($invoice->fresh()->pendingApprovalRequest(), $this->pm), $this->director);

        return $invoice->fresh();
    });
    expect($bill->net_payable)->toBe('38199.78');

    $receivable = fn () => openReport($this, 'client-receivables', [], $this->director, $this->project)
        ->assertOk();

    $receivable()->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.net_payable', '38199.78')
        ->where('result.totals.received', '0.00')
        ->where('result.totals.outstanding', '38199.78'));

    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.payments.store', $this->project), [
            'party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '20000',
            'payment_date' => now()->toDateString(), 'mode' => 'bank_transfer', 'bank_reference' => 'NEFT-1',
            'allocations' => [['payable_id' => $bill->id, 'amount' => '20000']],
        ])->assertSessionHasNoErrors();
    $first = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());

    // A draft receipt is not cash and not a settlement.
    $receivable()->assertInertia(fn (Assert $page) => $page->where('result.totals.outstanding', '38199.78'));

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.approve', [$this->project, $first]))->assertSessionHasNoErrors();

    $receivable()->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.received', '20000.00')
        ->where('result.totals.outstanding', '18199.78')
        ->where('result.rows.0.flag', null));

    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.payments.store', $this->project), [
            'party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '18199.78',
            'payment_date' => now()->toDateString(), 'mode' => 'bank_transfer', 'bank_reference' => 'NEFT-2',
            'allocations' => [['payable_id' => $bill->id, 'amount' => '18199.78']],
        ])->assertSessionHasNoErrors();
    $second = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());
    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.approve', [$this->project, $second]))->assertSessionHasNoErrors();

    $receivable()->assertInertia(fn (Assert $page) => $page->where('result.totals.outstanding', '0.00')->where('result.totals.received', '38199.78'));

    $this->inCompany($this->company, fn () => app(PaymentService::class)->cancel($first->fresh(), $this->director, 'Returned by the bank'));

    $receivable()->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.received', '18199.78')
        ->where('result.totals.outstanding', '20000.00'));
    expect($bill->fresh()->status->value)->not->toBe('draft');
});

test('subcontract and labour payables are certified or approved amounts less allocations, and retention stays a deduction', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['35', '10']));
    // 35×250 + 10×1,800 = 26,750. GST 18% = 4,815. Retention 5% = 1,337.50. TDS 1% = 267.50.
    // No advance is recovered on this bill, so net payable = 26,750 + 4,815 − 1,337.50 − 267.50 = 29,960.
    expect($bill->net_payable)->toBe('29960.00')
        ->and($bill->retention_amount)->toBe('1337.50')
        ->and($bill->advance_recovery)->toBe('0.00');

    $payables = fn (array $query = []) => openReport($this, 'payables', $query, $this->director, $this->project)->assertOk();

    $payables(['view' => 'subcontract'])->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.due', '29960.00')
        ->where('result.totals.settled', '0.00')
        ->where('result.totals.outstanding', '29960.00')
        ->where('result.rows.0.deductions', fn ($d) => Decimal::of($d)->equals(Decimal::of($bill->retention_amount)->plus($bill->advance_recovery)->plus($bill->tds_amount)->plus($bill->other_deductions))));

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.payments.store', $this->project), [
        'party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '10000',
        'payment_date' => now()->toDateString(), 'mode' => 'bank_transfer', 'bank_reference' => 'NEFT-S',
        'allocations' => [['payable_id' => $bill->id, 'amount' => '10000']],
    ])->assertSessionHasNoErrors();
    $payment = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());
    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $payment]))->assertSessionHasNoErrors();

    $payables(['view' => 'subcontract'])->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.settled', '10000.00')
        ->where('result.totals.outstanding', '19960.00')
        ->where('result.totals.due', '29960.00'));
    expect($this->netCost(CostHead::Subcontract))->toBe('26750.00');

    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2'],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day'],
    ], now()->subDay()->toDateString());
    $batch = $this->inCompany($this->company, function () {
        $service = app(LabourPaymentService::class);
        $created = $service->create($this->project, ['period_from' => now()->subDays(2)->toDateString(), 'period_to' => now()->toDateString()]);
        $service->submit($created, $this->accountant);
        $service->approve($created->fresh(), $this->pm);

        return $created->fresh();
    });
    expect($batch->total_net)->toBe('1340.00');

    $payables(['view' => 'labour'])->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.due', '1340.00')
        ->where('result.totals.outstanding', '1340.00'));

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.payments.store', $this->project), [
        'party_type' => 'labour_payment', 'party_id' => $batch->id, 'amount' => '500',
        'payment_date' => now()->toDateString(), 'mode' => 'cash', 'bank_reference' => 'CASH-1',
        'allocations' => [['payable_id' => $batch->id, 'amount' => '500']],
    ])->assertSessionHasNoErrors();
    $labourPay = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());
    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $labourPay]))->assertSessionHasNoErrors();

    $payables(['view' => 'labour'])->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.settled', '500.00')
        ->where('result.totals.outstanding', '840.00'));

    $this->inCompany($this->company, fn () => app(PaymentService::class)->cancel($labourPay->fresh(), $this->director, 'Wrong batch'));
    $payables(['view' => 'labour'])->assertInertia(fn (Assert $page) => $page->where('result.totals.outstanding', '1340.00')->where('result.totals.settled', '0.00'));
    expect($batch->fresh())->toBeInstanceOf(LabourPayment::class);
});

test('cash flow counts only cash movements and stays apart from project cost', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['35', '10']));
    expect($this->netCost(CostHead::Subcontract))->toBe('26750.00');

    $cash = fn () => openReport($this, 'cash-flow', [], $this->director, $this->project)->assertOk();
    $cash()->assertInertia(fn (Assert $page) => $page
        ->where('result.cards', fn ($cards) => collect($cards)->firstWhere('key', 'outflow')['value'] === '0.00'
            && collect($cards)->firstWhere('key', 'inflow')['value'] === '0.00'
            && collect($cards)->firstWhere('key', 'net')['value'] === '0.00'));

    $this->inCompany($this->company, function () {
        $petty = app(PettyCashService::class);
        $account = $petty->createAccount($this->project, ['name' => 'Site float', 'holder_user_id' => $this->engineer->id, 'limit_amount' => '50000']);
        $petty->fund($account, $this->accountant, ['amount' => '5000', 'txn_date' => now()->toDateString()]);
        $petty->returnCash($account, $this->accountant, ['amount' => '1500', 'txn_date' => now()->toDateString()]);
    });

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.payments.store', $this->project), [
        'party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '10000',
        'payment_date' => now()->toDateString(), 'mode' => 'bank_transfer', 'bank_reference' => 'NEFT-C',
        'allocations' => [['payable_id' => $bill->id, 'amount' => '10000']],
    ])->assertSessionHasNoErrors();
    $payment = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());

    // Draft payment is not cash. Cost is unchanged by the payment even after approval.
    $cash()->assertInertia(fn (Assert $page) => $page
        ->where('result.cards', fn ($cards) => collect($cards)->firstWhere('key', 'outflow')['value'] === '5000.00'
            && collect($cards)->firstWhere('key', 'inflow')['value'] === '1500.00'));

    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $payment]))->assertSessionHasNoErrors();

    $cash()->assertInertia(fn (Assert $page) => $page
        ->where('result.cards', fn ($cards) => collect($cards)->firstWhere('key', 'inflow')['value'] === '1500.00'
            && collect($cards)->firstWhere('key', 'outflow')['value'] === '15000.00'
            && collect($cards)->firstWhere('key', 'net')['value'] === '-13500.00'));
    expect($this->netCost(CostHead::Subcontract))->toBe('26750.00');
});

test('the April to March financial year keeps 31 March and 1 April in different years', function () {
    postHeads($this, ['overhead' => '111.11'], '2026-03-31', $this->project);
    postHeads($this, ['overhead' => '222.22'], '2026-04-01', $this->task);

    openReport($this, 'cost-by-head', ['fy' => '2025-26'], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.net', '111.11')->where('period.label', 'FY 2025-26'));

    openReport($this, 'cost-by-head', ['fy' => '2026-27'], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.net', '222.22')->where('period.label', 'FY 2026-27'));

    openReport($this, 'cost-by-head', ['from' => '2026-03-31', 'to' => '2026-03-31'], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.net', '111.11'));
});

test('a company report never includes another company, and a project member never sees the other project', function () {
    approveManualBudget($this, ['material' => '1000']);
    postHeads($this, ['material' => '400'], now()->toDateString());

    $other = $this->createCompany(['name' => 'Other Builders', 'code' => 'OTHER']);
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->inCompany($other, function () use ($otherAdmin) {
        $project = app(ProjectService::class)->create(['name' => 'Foreign site', 'project_manager_id' => $otherAdmin->id]);
        app(ProjectCostLedgerService::class)->post($project, $project->id, CostHead::Material, Decimal::of('999999'), now()->toDateString(), userId: $otherAdmin->id);
    });

    openReport($this, 'budget-vs-actual')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.actual', '400.00')->where('result.totals.budget', '1000.00'));

    ProjectUser::query()->where('project_id', $this->otherProject->id)->where('user_id', $this->billing->id)->delete();
    $this->inCompany($this->company, fn () => app(ProjectCostLedgerService::class)->post(
        $this->otherProject, $this->otherProject->id, CostHead::Material, Decimal::of('7777'), now()->toDateString(), userId: $this->director->id,
    ));
    $this->inCompany($this->company, function () {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->billing->givePermissionTo('reports.view_financial');
    });

    openReport($this, 'budget-vs-actual', [], $this->billing)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.actual', '400.00')
            ->where('result.rows', fn ($rows) => collect($rows)->every(fn ($row) => ! str_contains($row['label'], 'Tower B'))));

    openReport($this, 'budget-vs-actual', [], $this->director)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.actual', '8177.00'));
});

test('project dashboard money matches the ledgers and is omitted without the financial permission', function () {
    approveManualBudget($this, ['material' => '100000', 'labour' => '50000']);
    postHeads($this, ['material' => '25000', 'labour' => '10000'], now()->toDateString());
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    expect($order->subtotal)->toBe('61000.00');

    $this->actingInCompany($this->director, $this->company)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.financial.budget', '150000.00')
            ->where('dashboard.financial.actual', '35000.00')
            ->where('dashboard.financial.committed', '61000.00')
            ->where('dashboard.financial.remaining', '54000.00')
            ->where('dashboard.financial.utilization', '23.33')
            ->where('dashboard.operational.open_ncrs', 0));

    $this->actingInCompany($this->director, $this->company)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.kpis.total_projects', 2)
            ->where('dashboard.kpis.active_projects', 0)
            ->where('dashboard.kpis.budget', '150000.00')
            ->where('dashboard.kpis.actual_cost', '35000.00')
            ->where('dashboard.kpis.material_cost', '25000.00')
            ->where('dashboard.kpis.labour_cost', '10000.00')
            ->where('dashboard.kpis.equipment_cost', '0.00')
            ->where('dashboard.kpis.subcontract_cost', '0.00')
            ->where('dashboard.kpis.outstanding_receivables', '0.00')
            ->where('dashboard.kpis.vendor_payables', '0.00')
            ->where('dashboard.kpis.purchase_value', '0.00')
            ->where('dashboard.charts.cost_by_head.series.0', '25000.00')
            ->where('dashboard.kpis.project_value', '0.00'));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('dashboard.financial')
            ->where('dashboard.operational.tasks', fn ($n) => $n >= 1)
            ->where('project.contract_value', null));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.kpis.total_projects', 2)
            ->missing('dashboard.kpis.actual_cost')
            ->missing('dashboard.kpis.budget')
            ->missing('dashboard.charts.cost_by_head')
            ->missing('dashboard.charts.cash_flow'));
});

test('dashboard cache keeps financial figures off the non-financial response and drops them when cost is posted', function () {
    approveManualBudget($this, ['material' => '10000']);
    postHeads($this, ['material' => '1000'], now()->toDateString());

    $this->actingInCompany($this->director, $this->company)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.financial.actual', '1000.00'));

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('dashboard.financial'));

    postHeads($this, ['labour' => '250'], now()->toDateString());

    $this->actingInCompany($this->director, $this->company)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.financial.actual', '1250.00'));
});

test('progress completed quantity is the net ledger, including after a reversal, not the cached task quantity', function () {
    $dpr = $this->postProgress('4', now()->subDay()->toDateString());

    openReport($this, 'project-progress', [], $this->pm, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', fn ($rows) => collect($rows)->firstWhere('task', '1.1 Raft concrete')['done'] === '4.0000'));

    $this->inCompany($this->company, fn () => $this->task->forceFill(['completed_qty' => '99'])->save());

    openReport($this, 'project-progress', [], $this->pm, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['task'] === '1.1 Raft concrete' && $row['done'] === '4.0000' && $row['flag'] === 'Cached quantity differs from the ledger')));

    $this->inCompany($this->company, fn () => app(DprService::class)->reopen($dpr, $this->pm, 'Wrong quantity'));

    openReport($this, 'project-progress', [], $this->pm, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', fn ($rows) => collect($rows)->firstWhere('task', '1.1 Raft concrete')['done'] === '0.0000'));

    $posted = $this->postProgress('3', now()->subDays(2)->toDateString());
    openReport($this, 'boq-planned-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', fn ($rows) => collect($rows)->contains(fn ($row) => str_starts_with((string) $row['item'], 'A.1') && $row['executed'] === '3.0000')));
    $this->inCompany($this->company, fn () => app(DprService::class)->reopen($posted, $this->pm, 'Undo the BOQ quantity'));
    openReport($this, 'boq-planned-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', fn ($rows) => collect($rows)->contains(fn ($row) => str_starts_with((string) $row['item'], 'A.1') && $row['executed'] === '0.0000')));
});

test('a date filter excludes cost posted outside the range', function () {
    postHeads($this, ['material' => '10'], '2026-04-02', $this->project);
    postHeads($this, ['material' => '20'], '2026-04-10', $this->task);

    openReport($this, 'cost-by-head', ['from' => '2026-04-01', 'to' => '2026-04-05'], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.net', '10.00'));
});

test('an empty project report is zero rather than an error', function () {
    openReport($this, 'budget-vs-actual', [], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.budget', '0.00')
            ->where('result.totals.actual', '0.00')
            ->where('result.totals.utilization', null));

    openReport($this, 'cash-flow', ['from' => '2020-01-01', 'to' => '2020-01-31'], $this->director, $this->project)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.cards', fn ($cards) => collect($cards)->firstWhere('key', 'net')['value'] === '0.00'));
});

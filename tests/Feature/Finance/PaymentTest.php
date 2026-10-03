<?php

use App\Enums\CostHead;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Crm\Client;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Payment;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ClientInvoiceService;
use App\Services\Finance\PayableService;
use App\Services\Labour\LabourPaymentService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Unified receipts / payments on the resource fixtures: the project client pays a certified RA
 * bill (net 38,199.78), the subcontractor is paid a certified bill and a labour batch is settled.
 * The accountant records (payments.record); the director approves (payments.approve).
 */
beforeEach(function () {
    $this->setUpResources();
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
        $this->gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
    });
});

function certifiedClientBill($test, string $qty = '5', string $advance = '0'): ClientInvoice
{
    return $test->inCompany($test->company, function () use ($test, $qty, $advance) {
        $service = app(ClientInvoiceService::class);
        $invoice = $service->create($test->project, [
            'invoice_date' => now()->toDateString(),
            'period_from' => now()->subDays(10)->toDateString(),
            'period_to' => now()->toDateString(),
            'tax_rate_id' => $test->gst18,
            'retention_percent' => '5',
            'tds_percent' => '2',
            'advance_recovery' => $advance,
            'other_deductions' => '100',
            'items' => [['boq_item_id' => $test->boqLine->id, 'current_qty' => $qty]],
        ], $test->billing);
        $service->submit($invoice, $test->billing);
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($invoice->fresh()->pendingApprovalRequest(), $test->pm), $test->director);

        return $invoice->fresh();
    });
}

function recordMoney($test, array $data, $user = null)
{
    return $test->actingInCompany($user ?? $test->accountant, $test->company)->post(route('projects.payments.store', $test->project), $data + [
        'payment_date' => now()->toDateString(),
        'mode' => 'bank_transfer',
        'bank_reference' => 'NEFT-'.random_int(1000, 9999),
    ]);
}

function latestMoney($test): Payment
{
    return $test->inCompany($test->company, fn () => Payment::query()->latest('id')->firstOrFail());
}

function approveMoney($test, Payment $payment, $user = null)
{
    return $test->actingInCompany($user ?? $test->director, $test->company)->post(route('projects.payments.approve', [$test->project, $payment]));
}

function outstandingOf($test, $payable): string
{
    return $test->inCompany($test->company, fn () => app(PayableService::class)->outstanding($payable->fresh())->toMoney());
}

test('client receipts settle a certified RA bill partially then fully, rebuilding status and received amount', function () {
    $this->postProgress('8', now()->subDays(3)->toDateString());
    $bill = certifiedClientBill($this);
    expect($bill->net_payable)->toBe('38199.78');
    $ledgerBefore = $this->costs()->count();

    recordMoney($this, ['party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '20000', 'allocations' => [['payable_id' => $bill->id, 'amount' => '20000']]])
        ->assertSessionHasNoErrors();
    $first = latestMoney($this);
    expect($first->payment_number)->toBe('RCPT-PRJ001-0001')
        ->and($first->direction->value)->toBe('receipt')
        ->and($first->status)->toBe(PaymentStatus::Draft)
        ->and($bill->fresh()->received_amount)->toBe('0.00'); // drafts do not count

    approveMoney($this, $first)->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(ClientInvoiceStatus::PartiallyPaid)
        ->and($bill->fresh()->received_amount)->toBe('20000.00')
        ->and(outstandingOf($this, $bill))->toBe('18199.78');

    // Over-allocation of the outstanding is blocked.
    recordMoney($this, ['party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '20000', 'allocations' => [['payable_id' => $bill->id, 'amount' => '18199.79']]])
        ->assertSessionHasErrors('allocations.0.amount');

    recordMoney($this, ['party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '18199.78', 'allocations' => [['payable_id' => $bill->id, 'amount' => '18199.78']]])
        ->assertSessionHasNoErrors();
    approveMoney($this, latestMoney($this))->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(ClientInvoiceStatus::Paid)
        ->and($bill->fresh()->received_amount)->toBe('38199.78')
        ->and(outstandingOf($this, $bill))->toBe('0.00')
        ->and($this->costs()->count())->toBe($ledgerBefore);

    // Cancelling the first receipt reverses its effect.
    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.cancel', [$this->project, $first]), ['reason' => 'Cheque bounced'])
        ->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($first->fresh()->cancellation_reason)->toBe('Cheque bounced')
        ->and($bill->fresh()->status)->toBe(ClientInvoiceStatus::PartiallyPaid)
        ->and($bill->fresh()->received_amount)->toBe('18199.78')
        ->and(outstandingOf($this, $bill))->toBe('20000.00');
});

test('receipts come only from the project client; an unallocated receipt is an advance recovered on the next bill', function () {
    $other = $this->inCompany($this->company, fn () => Client::query()->create(['code' => 'CL002', 'company_name' => 'Other Client']));
    recordMoney($this, ['party_type' => 'client', 'party_id' => $other->id, 'amount' => '100'])->assertSessionHasErrors('party_id');

    recordMoney($this, ['party_type' => 'client', 'party_id' => $this->client->id, 'amount' => '5000', 'remarks' => 'Mobilisation advance'])->assertSessionHasNoErrors();
    $advance = latestMoney($this);
    approveMoney($this, $advance)->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => app(ClientInvoiceService::class)->advanceBalance($this->project->id, $this->client->id)->toMoney()))->toBe('5000.00');

    $this->postProgress('8', now()->subDays(3)->toDateString());
    expect(fn () => certifiedClientBill($this, '5', '5000.01'))->toThrow(Exception::class);
    $bill = certifiedClientBill($this, '5', '5000');
    expect($bill->net_payable)->toBe('33199.78') // 38,199.78 − 5,000 advance
        ->and($this->inCompany($this->company, fn () => app(ClientInvoiceService::class)->advanceBalance($this->project->id, $this->client->id)->toMoney()))->toBe('0.00');

    // The recovered advance receipt can no longer be cancelled.
    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.cancel', [$this->project, $advance]), ['reason' => 'Try to withdraw'])
        ->assertSessionHasErrors('payment');
    expect($advance->fresh()->status)->toBe(PaymentStatus::Approved);
});

test('subcontractor payments settle certified bills and never add project cost', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['35', '10']));
    $due = outstandingOf($this, $bill);
    $subcontractBefore = $this->netCost(CostHead::Subcontract);
    $ledgerBefore = $this->costs()->count();

    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '10000', 'allocations' => [['payable_id' => $bill->id, 'amount' => '10000']]])
        ->assertSessionHasNoErrors();
    $payment = latestMoney($this);
    expect($payment->payment_number)->toBe('PAY-PRJ001-0001')->and($payment->direction->value)->toBe('payment');
    approveMoney($this, $payment)->assertSessionHasNoErrors();

    expect($bill->fresh()->status)->toBe(SubcontractorBillStatus::PartiallyPaid)
        ->and($bill->fresh()->paid_amount)->toBe('10000.00')
        ->and($this->costs()->count())->toBe($ledgerBefore)
        ->and($this->netCost(CostHead::Subcontract))->toBe($subcontractBefore);

    $rest = outstandingOf($this, $bill);
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => $rest, 'allocations' => [['payable_id' => $bill->id, 'amount' => $rest]]])
        ->assertSessionHasNoErrors();
    approveMoney($this, latestMoney($this))->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(SubcontractorBillStatus::Paid)
        ->and($bill->fresh()->paid_amount)->toBe($due)
        ->and($this->costs()->count())->toBe($ledgerBefore);

    // Documents of another party cannot be allocated.
    $vendorPayment = ['party_type' => 'vendor', 'party_id' => $this->hireVendor->id, 'amount' => '10', 'allocations' => [['payable_id' => $bill->id, 'amount' => '10']]];
    recordMoney($this, $vendorPayment)->assertSessionHasErrors('allocations.0.payable_id');
});

test('a labour batch is settled through payments: partial keeps it approved, full marks it paid, cancel reopens it', function () {
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2'],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day'],
    ], now()->subDay()->toDateString());
    $batch = $this->inCompany($this->company, function () {
        $service = app(LabourPaymentService::class);
        $batch = $service->create($this->project, ['period_from' => now()->subDays(2)->toDateString(), 'period_to' => now()->toDateString()]);
        $service->submit($batch, $this->accountant);
        $service->approve($batch->fresh(), $this->pm);

        return $batch->fresh();
    });
    expect($batch->total_net)->toBe('1340.00');
    $ledgerBefore = $this->costs()->count();

    // Must be fully allocated to the batch.
    recordMoney($this, ['party_type' => 'labour_payment', 'party_id' => $batch->id, 'amount' => '1000', 'allocations' => [['payable_id' => $batch->id, 'amount' => '900']]])
        ->assertSessionHasErrors('allocations');

    recordMoney($this, ['party_type' => 'labour_payment', 'party_id' => $batch->id, 'amount' => '1000', 'mode' => 'cash', 'allocations' => [['payable_id' => $batch->id, 'amount' => '1000']]])
        ->assertSessionHasNoErrors();
    approveMoney($this, latestMoney($this))->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe(LabourPaymentStatus::Approved)->and($batch->fresh()->paid_amount)->toBe('1000.00');

    recordMoney($this, ['party_type' => 'labour_payment', 'party_id' => $batch->id, 'amount' => '340', 'allocations' => [['payable_id' => $batch->id, 'amount' => '340']]])
        ->assertSessionHasNoErrors();
    $final = latestMoney($this);
    approveMoney($this, $final)->assertSessionHasNoErrors();
    $batch = $batch->fresh();
    expect($batch->status)->toBe(LabourPaymentStatus::Paid)
        ->and($batch->paid_amount)->toBe('1340.00')
        ->and($batch->payment_reference)->toBe($final->payment_number)
        ->and($this->costs()->count())->toBe($ledgerBefore);

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.cancel', [$this->project, $final]), ['reason' => 'Paid to wrong person'])
        ->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe(LabourPaymentStatus::Approved)
        ->and($batch->fresh()->paid_amount)->toBe('1000.00')
        ->and($batch->fresh()->payment_reference)->toBeNull();

    // A batch marked paid directly in Phase 6 (no Finance allocation) cannot be settled again.
    $direct = (new LabourPayment)->forceFill(['status' => LabourPaymentStatus::Paid, 'paid_amount' => '0']);
    expect(app(PayableService::class)->isSettleable($direct))->toBeFalse();
});

test('maker-checker: the recorder cannot approve, approval needs payments.approve, approved payments are locked', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['10', '0']));

    // The company admin records; the admin cannot approve their own payment.
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '1000', 'allocations' => [['payable_id' => $bill->id, 'amount' => '1000']]], $this->admin)
        ->assertSessionHasNoErrors();
    $payment = latestMoney($this);
    approveMoney($this, $payment, $this->admin)->assertForbidden();
    approveMoney($this, $payment, $this->accountant)->assertForbidden(); // payments.record only
    approveMoney($this, $payment, $this->director)->assertSessionHasNoErrors();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Approved)->and($payment->fresh()->approved_by)->toBe($this->director->id);

    $this->actingInCompany($this->admin, $this->company)
        ->put(route('projects.payments.update', [$this->project, $payment]), ['payment_date' => now()->toDateString(), 'mode' => 'cash', 'amount' => '1'])
        ->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->delete(route('projects.payments.destroy', [$this->project, $payment]))->assertForbidden();
    approveMoney($this, $payment, $this->director)->assertForbidden();

    // Allocations above the payment amount are refused.
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '100', 'allocations' => [['payable_id' => $bill->id, 'amount' => '100.01']]])
        ->assertSessionHasErrors('allocations');
    // Petty cash is not a payment mode.
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '100', 'mode' => 'petty_cash'])
        ->assertSessionHasErrors('mode');
});

test('allocation is re-checked at approval so two drafts cannot over-settle one bill', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['10', '0']));
    $due = outstandingOf($this, $bill);

    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => $due, 'allocations' => [['payable_id' => $bill->id, 'amount' => $due]]])->assertSessionHasNoErrors();
    $a = latestMoney($this);
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => $due, 'allocations' => [['payable_id' => $bill->id, 'amount' => $due]]])->assertSessionHasNoErrors();
    $b = latestMoney($this);

    approveMoney($this, $a)->assertSessionHasNoErrors();
    approveMoney($this, $b)->assertSessionHasErrors('payment');
    expect($b->fresh()->status)->toBe(PaymentStatus::Draft)
        ->and($bill->fresh()->paid_amount)->toBe($due)
        ->and($bill->fresh()->status)->toBe(SubcontractorBillStatus::Paid);
});

test('payments are isolated by company and project', function () {
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $this->subcontractor->id, 'amount' => '500'])->assertSessionHasNoErrors();
    $payment = latestMoney($this);

    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.payments.show', [$this->otherProject, $payment]))->assertNotFound();
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.payments.index', $this->project))->assertForbidden();

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.payments.show', [$this->project, $payment]))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('projects.payments.approve', [$this->project, $payment]))->assertNotFound();

    // A subcontractor of another company is not a valid party.
    $foreignSub = $this->inCompany($other, fn () => Subcontractor::query()->create(['code' => 'SUBX', 'name' => 'Foreign']));
    recordMoney($this, ['party_type' => 'subcontractor', 'party_id' => $foreignSub->id, 'amount' => '500'])->assertSessionHasErrors('party_id');

    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.payments.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/Payments/Index')->has('payments.data', 1));
});

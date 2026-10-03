<?php

use App\Enums\CostHead;
use App\Enums\Labour\LabourPaymentStatus;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Labour\LabourPaymentLine;
use App\Services\Labour\LabourAdvanceService;
use App\Services\Labour\LabourAttendanceService;
use App\Services\Labour\LabourPaymentService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Day 1: Ravi present + 2 h OT (800 + 240), Sunil half day (300).
 * Day 2: Ravi present (800), Sunil present (600).
 * Today: Ravi marked, not approved. Ravi took a 2,000 advance on day 1.
 */
beforeEach(function () {
    $this->setUpResources();
    $this->day1 = now()->subDays(2)->toDateString();
    $this->day2 = now()->subDay()->toDateString();
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2'],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day'],
    ], $this->day1);
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present'],
        ['labour_id' => $this->sunil->id, 'status' => 'present'],
    ], $this->day2);
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present']]);

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.labour.advances.store', $this->project), [
        'labour_id' => $this->ravi->id, 'advance_date' => $this->day1, 'amount' => '2000', 'remarks' => 'Festival advance',
    ])->assertSessionHasNoErrors();
});

function paymentOf($test, ?int $id = null): LabourPayment
{
    return $test->inCompany($test->company, fn () => $id ? LabourPayment::query()->findOrFail($id) : LabourPayment::query()->latest('id')->firstOrFail());
}

function lineOf($test, LabourPayment $payment, $labour): LabourPaymentLine
{
    return $test->inCompany($test->company, fn () => LabourPaymentLine::query()->where('labour_payment_id', $payment->id)->where('labour_id', $labour->id)->firstOrFail());
}

function makePayment($test, ?string $from = null, ?string $to = null)
{
    return $test->actingInCompany($test->accountant, $test->company)->post(route('projects.labour-payments.store', $test->project), [
        'period_from' => $from ?? $test->day1, 'period_to' => $to ?? now()->toDateString(),
    ]);
}

test('an advance is recorded without any project cost', function () {
    $advance = $this->inCompany($this->company, fn () => LabourAdvance::query()->sole());
    expect($advance->amount)->toBe('2000.00')
        ->and($advance->recovered_amount)->toBe('0.00')
        ->and($this->costs())->toHaveCount(4); // the four approved attendance days only
});

test('a payment batch collects approved, unpaid attendance only and computes lines on the server', function () {
    makePayment($this)->assertSessionHasNoErrors();

    $payment = paymentOf($this);
    $ravi = lineOf($this, $payment, $this->ravi);
    $sunil = lineOf($this, $payment, $this->sunil);
    expect($payment->payment_number)->toBe('LP-PRJ001-0001')
        ->and($payment->status)->toBe(LabourPaymentStatus::Draft)
        ->and($payment->company_id)->toBe($this->company->id)
        ->and($ravi->present_days)->toBe('2.0')
        ->and($ravi->gross_wage)->toBe('1600.00')
        ->and($ravi->ot_hours)->toBe('2.00')
        ->and($ravi->ot_amount)->toBe('240.00')
        ->and($ravi->net_amount)->toBe('1840.00')
        ->and($sunil->present_days)->toBe('1.0')
        ->and($sunil->half_days)->toBe('1.0')
        ->and($sunil->gross_wage)->toBe('900.00')
        ->and($payment->total_gross)->toBe('2500.00')
        ->and($payment->total_net)->toBe('2740.00')
        ->and($this->inCompany($this->company, fn () => LabourAttendance::query()->where('labour_payment_id', $payment->id)->count()))->toBe(4)
        ->and($this->attendanceOf($this->ravi)->labour_payment_id)->toBeNull(); // today's unapproved day is excluded
});

test('attendance is never paid twice and overlapping periods are flagged', function () {
    makePayment($this)->assertSessionHasNoErrors();
    $first = paymentOf($this);

    makePayment($this)->assertSessionHasErrors('period_from');
    expect($this->inCompany($this->company, fn () => LabourPayment::query()->count()))->toBe(1);

    // Approve today's day and pay it separately: the period overlaps the first batch but no day repeats.
    $this->inCompany($this->company, fn () => app(LabourAttendanceService::class)->approve($this->project, [$this->attendanceOf($this->ravi)->id], $this->pm));
    makePayment($this, now()->toDateString(), now()->toDateString())->assertSessionHasNoErrors();
    $second = paymentOf($this);

    expect($second->id)->not->toBe($first->id)
        ->and($second->total_gross)->toBe('800.00')
        ->and($this->inCompany($this->company, fn () => app(LabourPaymentService::class)->overlapping($second)->pluck('id')->all()))->toBe([$first->id]);

    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.labour-payments.show', [$this->project, $second]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Labour/Payments/Show')->has('overlaps', 1)->has('days', 1));

    // Approved attendance in a payment can no longer be un-approved.
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.attendance.unapprove', [$this->project, $this->attendanceOf($this->ravi, $this->day1)]), ['reason' => 'Try to undo'])
        ->assertForbidden();
});

test('advance recovery and deductions give the exact net and are bounded by the outstanding advance', function () {
    makePayment($this)->assertSessionHasNoErrors();
    $payment = paymentOf($this);
    $raviLine = lineOf($this, $payment, $this->ravi);
    $sunilLine = lineOf($this, $payment, $this->sunil);
    $accountant = $this->actingInCompany($this->accountant, $this->company);
    $url = route('projects.labour-payments.update', [$this->project, $payment]);

    $accountant->put($url, ['lines' => [['id' => $raviLine->id, 'advance_recovery' => '2000.01']]])->assertSessionHasErrors('lines.0.advance_recovery');
    $accountant->put($url, ['lines' => [['id' => $sunilLine->id, 'advance_recovery' => '1']]])->assertSessionHasErrors('lines.0.advance_recovery');
    $accountant->put($url, ['lines' => [['id' => $raviLine->id, 'other_deductions' => '1840.01']]])->assertSessionHasErrors();

    $accountant->put($url, ['lines' => [
        ['id' => $raviLine->id, 'advance_recovery' => '1500', 'other_deductions' => '40.50', 'remarks' => 'Canteen'],
        ['id' => $sunilLine->id],
    ]])->assertSessionHasNoErrors();

    $payment = paymentOf($this, $payment->id);
    expect(lineOf($this, $payment, $this->ravi)->net_amount)->toBe('299.50')   // 1,600 + 240 − 1,500 − 40.50
        ->and(lineOf($this, $payment, $this->sunil)->net_amount)->toBe('900.00')
        ->and($payment->total_deductions)->toBe('1540.50')
        ->and($payment->total_net)->toBe('1199.50')
        // Pending recovery reserves the advance until approval; nothing is recovered yet.
        ->and($this->inCompany($this->company, fn () => LabourAdvance::query()->sole()->recovered_amount))->toBe('0.00')
        ->and($this->inCompany($this->company, fn () => app(LabourAdvanceService::class)->recoverable($this->ravi->id)->toMoney()))->toBe('500.00');
});

test('maker-checker approval applies recoveries deterministically and posts no labour cost again', function () {
    makePayment($this)->assertSessionHasNoErrors();
    $payment = paymentOf($this);
    $raviLine = lineOf($this, $payment, $this->ravi);
    $accountant = $this->actingInCompany($this->accountant, $this->company);
    $accountant->put(route('projects.labour-payments.update', [$this->project, $payment]), ['lines' => [['id' => $raviLine->id, 'advance_recovery' => '1500']]])->assertSessionHasNoErrors();
    $costsBefore = $this->costs()->count();
    $labourBefore = $this->netCost(CostHead::Labour);

    $accountant->post(route('projects.labour-payments.submit', [$this->project, $payment]))->assertSessionHasNoErrors();
    expect(fn () => $this->inCompany($this->company, fn () => app(LabourPaymentService::class)->updateLines(paymentOf($this, $payment->id), [['id' => $raviLine->id, 'advance_recovery' => '1']])))
        ->toThrow(Exception::class);

    $accountant->post(route('projects.labour-payments.approve', [$this->project, $payment]))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.labour-payments.approve', [$this->project, $payment]))->assertSessionHasNoErrors();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.labour-payments.approve', [$this->project, $payment]))->assertForbidden();

    $payment = paymentOf($this, $payment->id);
    $advance = $this->inCompany($this->company, fn () => LabourAdvance::query()->sole());
    expect($payment->status)->toBe(LabourPaymentStatus::Approved)
        ->and($payment->approved_by)->toBe($this->pm->id)
        ->and($advance->recovered_amount)->toBe('1500.00')
        ->and($this->costs()->count())->toBe($costsBefore)
        ->and($this->netCost(CostHead::Labour))->toBe($labourBefore);

    // Recomputing again is a no-op (recovered = sum of settled recoveries, never incremented).
    $this->inCompany($this->company, fn () => app(LabourAdvanceService::class)->recompute($this->ravi->id));
    expect($advance->fresh()->recovered_amount)->toBe('1500.00');

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.labour-payments.mark-paid', [$this->project, $payment]), [
        'paid_on' => now()->toDateString(), 'payment_reference' => 'NEFT-001',
    ])->assertSessionHasNoErrors();
    expect(paymentOf($this, $payment->id)->status)->toBe(LabourPaymentStatus::Paid)
        ->and($this->costs()->count())->toBe($costsBefore);

    // The advance with recoveries cannot be deleted.
    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.labour.advances.destroy', [$this->project, $advance]))->assertForbidden();
});

test('send back returns a payment to draft; deleting a draft frees its attendance', function () {
    makePayment($this)->assertSessionHasNoErrors();
    $payment = paymentOf($this);
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.labour-payments.submit', [$this->project, $payment]))->assertSessionHasNoErrors();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.labour-payments.send-back', [$this->project, $payment]), ['reason' => 'Check Sunil days'])->assertSessionHasNoErrors();
    expect(paymentOf($this, $payment->id)->status)->toBe(LabourPaymentStatus::Draft)
        ->and(paymentOf($this, $payment->id)->return_reason)->toBe('Check Sunil days');

    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.labour-payments.destroy', [$this->project, $payment]))->assertRedirect();
    expect($this->inCompany($this->company, fn () => LabourAttendance::query()->whereNotNull('labour_payment_id')->count()))->toBe(0);
    makePayment($this)->assertSessionHasNoErrors();
    expect(paymentOf($this)->payment_number)->toBe('LP-PRJ001-0002');
});

test('payments need labour.manage_payments and project access', function () {
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.labour-payments.store', $this->project), [
        'period_from' => $this->day1, 'period_to' => now()->toDateString(),
    ])->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.labour.advances.store', $this->project), [
        'labour_id' => $this->ravi->id, 'advance_date' => $this->day1, 'amount' => '100',
    ])->assertForbidden();

    makePayment($this)->assertSessionHasNoErrors();
    $payment = paymentOf($this);
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.labour-payments.show', [$this->otherProject, $payment]))->assertNotFound();
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.labour-payments.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Labour/Payments/Index')->has('payments.data', 1));
});

<?php

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\RetentionReleaseStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\RetentionRelease;
use App\Models\Masters\TaxRate;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ClientInvoiceService;
use App\Services\Finance\PayableService;
use App\Services\Finance\RetentionReleaseService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Client RA bill 1 (5 × 6,900.8625, retention 5 % = 1,725.22, net 38,199.78) and a certified
 * subcontractor bill (retention 5 %). Releases: billing engineer drafts; PM → Director approves.
 */
beforeEach(function () {
    $this->setUpResources();
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
    });
    $this->postProgress('8', now()->subDays(3)->toDateString());
    $this->invoice = $this->inCompany($this->company, function () {
        $service = app(ClientInvoiceService::class);
        $invoice = $service->create($this->project, [
            'invoice_date' => now()->toDateString(), 'period_from' => now()->subDays(10)->toDateString(), 'period_to' => now()->toDateString(),
            'tax_rate_id' => TaxRate::query()->where('name', 'GST 18%')->value('id'),
            'retention_percent' => '5', 'tds_percent' => '2', 'other_deductions' => '100',
            'items' => [['boq_item_id' => $this->boqLine->id, 'current_qty' => '5']],
        ], $this->billing);
        $service->submit($invoice, $this->billing);
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($invoice->fresh()->pendingApprovalRequest(), $this->pm), $this->director);

        return $invoice->fresh();
    });
});

function draftRelease($test, string $type, int $id, string $amount, $user = null)
{
    return $test->actingInCompany($user ?? $test->billing, $test->company)->post(route('projects.retention.store', $test->project), [
        'releasable_type' => $type, 'releasable_id' => $id, 'release_date' => now()->toDateString(), 'amount' => $amount, 'remarks' => 'Defect liability over',
    ]);
}

function latestRelease($test): RetentionRelease
{
    return $test->inCompany($test->company, fn () => RetentionRelease::query()->latest('id')->firstOrFail());
}

function approveRelease($test, RetentionRelease $release): RetentionRelease
{
    $test->actingInCompany($test->billing, $test->company)->post(route('projects.retention.submit', [$test->project, $release]))->assertSessionHasNoErrors();

    return $test->inCompany($test->company, function () use ($test, $release) {
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($release->fresh()->pendingApprovalRequest(), $test->pm), $test->director);

        return $release->fresh();
    });
}

function dueOf($test, $bill): string
{
    return $test->inCompany($test->company, fn () => app(PayableService::class)->due($bill->fresh())->toMoney());
}

test('retention held on the client bill is listed and partially released through the approval engine', function () {
    expect($this->invoice->retention_amount)->toBe('1725.22');

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.retention.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/Retention/Index')
            ->has('bills', 1)
            ->where('bills.0.held', '1725.22')
            ->where('bills.0.releasable', '1725.22'));

    draftRelease($this, 'client_invoice', $this->invoice->id, '1000')->assertSessionHasNoErrors();
    $release = latestRelease($this);
    expect($release->release_number)->toBe('RTR-PRJ001-0001')
        ->and($release->status)->toBe(RetentionReleaseStatus::Draft)
        ->and(dueOf($this, $this->invoice))->toBe('38199.78'); // a draft changes nothing

    $release = approveRelease($this, $release);
    expect($release->status)->toBe(RetentionReleaseStatus::Approved)
        ->and($release->approved_by)->toBe($this->director->id)
        ->and(dueOf($this, $this->invoice))->toBe('39199.78')
        ->and($this->invoice->fresh()->status)->toBe(ClientInvoiceStatus::Certified)
        ->and($this->costs()->count())->toBe(0)
        ->and($this->inCompany($this->company, fn () => Payment::query()->count()))->toBe(0); // not a cash movement

    // Locked after approval; idempotent approve.
    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.retention.update', [$this->project, $release]), ['release_date' => now()->toDateString(), 'amount' => '1'])
        ->assertForbidden();
    $this->inCompany($this->company, fn () => app(RetentionReleaseService::class)->approve($release->fresh(), $this->director->id));
    expect(dueOf($this, $this->invoice))->toBe('39199.78');
});

test('cumulative releases cannot exceed the retention held, counting submitted releases', function () {
    draftRelease($this, 'client_invoice', $this->invoice->id, '1725.23')->assertSessionHasErrors('amount');

    draftRelease($this, 'client_invoice', $this->invoice->id, '1000')->assertSessionHasNoErrors();
    $first = latestRelease($this);
    draftRelease($this, 'client_invoice', $this->invoice->id, '1000')->assertSessionHasNoErrors(); // drafts do not reserve
    $second = latestRelease($this);

    $this->actingInCompany($this->billing, $this->company)->post(route('projects.retention.submit', [$this->project, $first]))->assertSessionHasNoErrors();
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.retention.submit', [$this->project, $second]))->assertSessionHasErrors('amount');
    draftRelease($this, 'client_invoice', $this->invoice->id, '725.23')->assertSessionHasErrors('amount');

    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.retention.update', [$this->project, $second]), ['release_date' => now()->toDateString(), 'amount' => '725.22'])
        ->assertSessionHasNoErrors();
    approveRelease($this, $second);
    $this->inCompany($this->company, function () use ($first) {
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($first->fresh()->pendingApprovalRequest(), $this->pm), $this->director);
    });

    expect(dueOf($this, $this->invoice))->toBe('39925.00') // 38,199.78 + 1,000 + 725.22
        ->and($this->inCompany($this->company, fn () => app(RetentionReleaseService::class)->balance($this->invoice->fresh())->toMoney()))->toBe('0.00');
    draftRelease($this, 'client_invoice', $this->invoice->id, '0.01')->assertSessionHasErrors('amount');
});

test('settlement of released retention is a separate receipt allocated to the bill', function () {
    draftRelease($this, 'client_invoice', $this->invoice->id, '1725.22')->assertSessionHasNoErrors();
    approveRelease($this, latestRelease($this));
    expect(dueOf($this, $this->invoice))->toBe('39925.00');

    $accountant = $this->actingInCompany($this->accountant, $this->company);
    $accountant->post(route('projects.payments.store', $this->project), [
        'party_type' => 'client', 'party_id' => $this->client->id, 'payment_date' => now()->toDateString(), 'mode' => 'bank_transfer',
        'amount' => '39925', 'allocations' => [['payable_id' => $this->invoice->id, 'amount' => '39925']],
    ])->assertSessionHasNoErrors();
    $receipt = $this->inCompany($this->company, fn () => Payment::query()->latest('id')->firstOrFail());
    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $receipt]))->assertSessionHasNoErrors();

    $invoice = $this->invoice->fresh();
    expect($invoice->status)->toBe(ClientInvoiceStatus::Paid)
        ->and($invoice->received_amount)->toBe('39925.00')
        ->and($invoice->retention_amount)->toBe('1725.22') // the held figure on the bill is never rewritten
        ->and(latestRelease($this)->amount)->toBe('1725.22')
        ->and($this->costs()->count())->toBe(0);
});

test('subcontractor retention is released against a certified bill and approved by subcontract.certify_bill', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $bill = $this->certifyBill($this->makeBill($order, ['35', '10']));
    $held = $bill->retention_amount;
    $dueBefore = dueOf($this, $bill);
    expect($held)->not->toBe('0.00');
    $costBefore = $this->costs()->count();

    draftRelease($this, 'subcontractor_bill', $bill->id, '100.005')->assertSessionHasErrors('amount');
    draftRelease($this, 'subcontractor_bill', $bill->id, '500')->assertSessionHasNoErrors();
    $release = approveRelease($this, latestRelease($this));

    expect($release->status)->toBe(RetentionReleaseStatus::Approved)
        ->and(dueOf($this, $bill))->toBe(Decimal::of($dueBefore)->plus('500')->toMoney())
        ->and($bill->fresh()->status)->toBe(SubcontractorBillStatus::Certified)
        ->and($this->costs()->count())->toBe($costBefore);
});

test('releases need a certified bill of the project and billing / subcontract permissions', function () {
    // The site engineer holds neither billing.create nor subcontract.create.
    draftRelease($this, 'client_invoice', $this->invoice->id, '100', $this->engineer)->assertForbidden();

    // A draft bill of the project is not releasable.
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    $draftBill = $this->makeBill($order, ['10', '0']);
    draftRelease($this, 'subcontractor_bill', $draftBill->id, '1')->assertSessionHasErrors('releasable_id');

    // Another tenant cannot see or post.
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.retention.index', $this->project))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('projects.retention.store', $this->project), [
        'releasable_type' => 'client_invoice', 'releasable_id' => $this->invoice->id, 'release_date' => now()->toDateString(), 'amount' => '1',
    ])->assertNotFound();

    // The bill must belong to the project in the URL.
    $this->actingInCompany($this->admin, $this->company)->post(route('projects.retention.store', $this->otherProject), [
        'releasable_type' => 'client_invoice', 'releasable_id' => $this->invoice->id, 'release_date' => now()->toDateString(), 'amount' => '1',
    ])->assertSessionHasErrors('releasable_id');
    expect($this->inCompany($this->company, fn () => RetentionRelease::query()->count()))->toBe(0);
});

<?php

use App\Enums\CostHead;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Core\AuditLog;
use App\Models\Crm\Client;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\ClientInvoiceItem;
use App\Models\Masters\TaxRate;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ClientInvoiceService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Client RA bills on BOQ line A.1 (Excavation, BOQ qty 12.5, client rate 6,900.8625), linked to
 * task 1.1. The company and the project are in Maharashtra (27) unless a test moves the project.
 * Billing engineer drafts and submits; PM → Director (billing.certify) certifies.
 */
beforeEach(function () {
    $this->setUpResources();
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
        $this->gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
    });
    $this->postProgress('8', now()->subDays(3)->toDateString());
});

function raPayload($test, array $lines, array $overrides = []): array
{
    return array_replace([
        'invoice_date' => now()->toDateString(),
        'period_from' => now()->subDays(10)->toDateString(),
        'period_to' => now()->toDateString(),
        'tax_rate_id' => $test->gst18,
        'retention_percent' => '5',
        'tds_percent' => '2',
        'advance_recovery' => '0',
        'other_deductions' => '100',
        'items' => $lines,
    ], $overrides);
}

function raLine($test, ?string $qty, ?string $reason = null): array
{
    return ['boq_item_id' => $test->boqLine->id, 'current_qty' => $qty, 'override_reason' => $reason];
}

function draftRa($test, string $qty, array $overrides = [], $user = null): ClientInvoice
{
    $test->actingInCompany($user ?? $test->billing, $test->company)
        ->post(route('projects.ra-bills.store', $test->project), raPayload($test, [raLine($test, $qty)], $overrides))
        ->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => ClientInvoice::query()->latest('id')->firstOrFail());
}

function certifyRa($test, ClientInvoice $invoice): ClientInvoice
{
    return $test->inCompany($test->company, function () use ($test, $invoice) {
        app(ClientInvoiceService::class)->submit($invoice->fresh(), $test->billing);
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($invoice->fresh()->pendingApprovalRequest(), $test->pm);
        $approvals->approve($request, $test->director);

        return $invoice->fresh();
    });
}

function raItem($test, ClientInvoice $invoice): ClientInvoiceItem
{
    return $test->inCompany($test->company, fn () => ClientInvoiceItem::query()->where('client_invoice_id', $invoice->id)->sole());
}

test('the measurement form shows executed, previous and billable quantities from progress and the BOQ', function () {
    $this->actingInCompany($this->billing, $this->company)->get(route('projects.ra-bills.create', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/ClientBills/Form')
            ->where('taxType', 'intra')
            ->where('lines.0.boq_line_uid', $this->boqLine->line_uid)
            ->where('lines.0.boq_qty', '12.5000')
            ->where('lines.0.executed_qty', '8.0000')
            ->where('lines.0.previous_qty', '0.0000')
            ->where('lines.0.balance_qty', '8.0000')
            ->where('lines.0.rate', '6900.8625'));

    // Progress after period_to is not executed for this bill.
    $this->actingInCompany($this->billing, $this->company)->get(route('projects.ra-bills.create', [$this->project, 'period_to' => now()->subDays(4)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('lines.0.executed_qty', '0.0000'));
});

test('an intra-state RA bill gives the exact GST, retention, TDS and net payable (hand-checked)', function () {
    $bill = draftRa($this, '5');
    $item = raItem($this, $bill);

    // 5 × 6,900.8625 = 34,504.3125 → 34,504.31; CGST 9 % = 3,105.39, SGST 3,105.39; total 40,715.09;
    // retention 5 % of gross = 1,725.22; TDS 2 % = 690.09; other 100 → net 38,199.78.
    expect($bill->ra_sequence)->toBe(1)
        ->and($bill->invoice_number)->toBeNull()
        ->and($bill->status)->toBe(ClientInvoiceStatus::Draft)
        ->and($bill->client_id)->toBe($this->client->id)
        ->and($bill->tax_type)->toBe(TaxType::Intra)
        ->and($item->boq_line_uid)->toBe($this->boqLine->line_uid)
        ->and($item->executed_qty)->toBe('8.0000')
        ->and($item->previous_qty)->toBe('0.0000')
        ->and($item->current_qty)->toBe('5.0000')
        ->and($item->cumulative_qty)->toBe('5.0000')
        ->and($item->rate)->toBe('6900.8625')
        ->and($item->current_amount)->toBe('34504.31')
        ->and($bill->gross_amount)->toBe('34504.31')
        ->and($bill->cgst_amount)->toBe('3105.39')
        ->and($bill->sgst_amount)->toBe('3105.39')
        ->and($bill->igst_amount)->toBe('0.00')
        ->and($bill->tax_amount)->toBe('6210.78')
        ->and($bill->invoice_total)->toBe('40715.09')
        ->and($bill->retention_amount)->toBe('1725.22')
        ->and($bill->tds_amount)->toBe('690.09')
        ->and($bill->net_payable)->toBe('38199.78')
        ->and($this->costs())->toHaveCount(0); // client bills never touch the cost ledger
});

test('an inter-state RA bill charges IGST', function () {
    $this->inCompany($this->company, fn () => $this->project->forceFill(['state_code' => '29'])->save());
    $bill = draftRa($this, '5', ['retention_percent' => '0', 'tds_percent' => '0', 'other_deductions' => '0']);

    expect($bill->tax_type)->toBe(TaxType::Inter)
        ->and($bill->cgst_amount)->toBe('0.00')
        ->and($bill->sgst_amount)->toBe('0.00')
        ->and($bill->igst_amount)->toBe('6210.78')
        ->and($bill->invoice_total)->toBe('40715.09')
        ->and($bill->net_payable)->toBe('40715.09');
});

test('certification assigns the invoice number, locks the bill and feeds the previous quantity of the next bill', function () {
    $bill = draftRa($this, '5');
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.ra-bills.submit', [$this->project, $bill]))->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(ClientInvoiceStatus::Submitted)->and($bill->fresh()->invoice_number)->toBeNull();

    // Only one open bill per project.
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '1')]))
        ->assertSessionHasErrors('project');

    $this->inCompany($this->company, function () use ($bill) {
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($bill->fresh()->pendingApprovalRequest(), $this->pm);
        $approvals->approve($request, $this->director);
    });
    $bill = $bill->fresh();
    expect($bill->status)->toBe(ClientInvoiceStatus::Certified)
        ->and($bill->invoice_number)->toBe('INV-PRJ001-0001')
        ->and($bill->certified_by)->toBe($this->director->id);

    // Idempotent certify.
    $this->inCompany($this->company, fn () => app(ClientInvoiceService::class)->certify($bill->fresh(), $this->director->id));
    expect($bill->fresh()->invoice_number)->toBe('INV-PRJ001-0001');

    // Locked.
    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.ra-bills.update', [$this->project, $bill]), raPayload($this, [raLine($this, '1')]))
        ->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(ClientInvoiceService::class)->update($bill->fresh(), raPayload($this, [raLine($this, '1')]), $this->billing)))
        ->toThrow(Exception::class);
    $this->actingInCompany($this->admin, $this->company)->delete(route('projects.ra-bills.destroy', [$this->project, $bill]))->assertForbidden();

    // Bill 2: previous = 5 by line_uid; executed 8 → at most 3 more.
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '3.0001')]))
        ->assertSessionHasErrors('items.0.current_qty');
    $second = draftRa($this, '3');
    $item = raItem($this, $second);
    expect($second->ra_sequence)->toBe(2)
        ->and($item->boq_line_uid)->toBe($this->boqLine->line_uid)
        ->and($item->previous_qty)->toBe('5.0000')
        ->and($item->current_qty)->toBe('3.0000')
        ->and($item->cumulative_qty)->toBe('8.0000')
        ->and($item->current_amount)->toBe('20702.59'); // 3 × 6,900.8625 = 20,702.5875

    $second = certifyRa($this, $second);
    expect($second->invoice_number)->toBe('INV-PRJ001-0002');
});

test('cumulative quantity is capped by the BOQ quantity even when more is executed', function () {
    // The current BOQ line carries less than was executed (e.g. after a scope cut): 6 < 8.
    $this->inCompany($this->company, fn () => $this->boqLine->forceFill(['quantity' => '6'])->saveQuietly());

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '6.0001')]))
        ->assertSessionHasErrors('items.0.current_qty');
    $bill = draftRa($this, '6');
    expect(raItem($this, $bill)->executed_qty)->toBe('8.0000')
        ->and(raItem($this, $bill)->boq_qty)->toBe('6.0000')
        ->and(raItem($this, $bill)->cumulative_qty)->toBe('6.0000');
});

test('billing beyond the limits needs billing.override_qty and an audited justification', function () {
    // The billing engineer does not hold the override.
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '9', 'Joint measurement with the client engineer')]))
        ->assertSessionHasErrors('items.0.current_qty');

    // Company admin holds it, but must justify.
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '9', 'Too short')]))
        ->assertSessionHasErrors('items.0.override_reason');

    $bill = draftRa($this, '9', ['items' => [raLine($this, '9', 'Joint measurement with the client engineer')]], $this->admin);
    $item = raItem($this, $bill);
    expect($item->is_override)->toBeTrue()
        ->and($item->override_reason)->toBe('Joint measurement with the client engineer')
        ->and($item->cumulative_qty)->toBe('9.0000');

    $audit = $this->inCompany($this->company, fn () => AuditLog::query()->where('event', 'quantity_override')->sole());
    expect($audit->auditable_id)->toBe($bill->id)
        ->and($audit->new_values['reason'])->toBe('Joint measurement with the client engineer')
        ->and($audit->new_values['executed_qty'])->toBe('8.0000');

    // The approved override survives certification.
    $bill = certifyRa($this, $bill);
    expect($bill->status)->toBe(ClientInvoiceStatus::Certified);
});

test('a never-submitted draft can be deleted and its RA sequence is reused', function () {
    $bill = draftRa($this, '2');
    $this->actingInCompany($this->billing, $this->company)->delete(route('projects.ra-bills.destroy', [$this->project, $bill]))->assertForbidden(); // billing.delete
    $this->actingInCompany($this->admin, $this->company)->delete(route('projects.ra-bills.destroy', [$this->project, $bill]))->assertRedirect();

    expect(draftRa($this, '2')->ra_sequence)->toBe(1);
});

test('the RA bill PDF downloads and needs billing.export', function () {
    $bill = certifyRa($this, draftRa($this, '5'));

    $response = $this->actingInCompany($this->billing, $this->company)->get(route('projects.ra-bills.pdf', [$this->project, $bill]));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('INV-PRJ001-0001.pdf');

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.ra-bills.pdf', [$this->project, $bill]))->assertForbidden();
});

test('RA bills are isolated by company and project and need billing permissions', function () {
    $bill = draftRa($this, '5');

    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ra-bills.store', $this->project), raPayload($this, [raLine($this, '1')]))->assertForbidden();
    $this->actingInCompany($this->billing, $this->company)->get(route('projects.ra-bills.show', [$this->otherProject, $bill]))->assertNotFound();

    $stranger = $this->createMember($this->company, DefaultRoles::BILLING_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->get(route('projects.ra-bills.show', [$this->project, $bill]))->assertForbidden();

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.ra-bills.show', [$this->project, $bill]))->assertNotFound();
    $this->actingInCompany($outsider, $other)->get(route('projects.ra-bills.pdf', [$this->project, $bill]))->assertNotFound();

    // Without a client on the project no bill can be raised.
    $this->inCompany($this->company, fn () => $this->otherProject->forceFill(['state_code' => '27'])->save());
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.ra-bills.store', $this->otherProject), raPayload($this, [raLine($this, '1')]))
        ->assertSessionHasErrors();
    expect($this->netCost(CostHead::Material))->toBe('0.00');
});

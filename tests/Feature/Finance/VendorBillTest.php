<?php

use App\Enums\CostHead;
use App\Enums\Finance\VendorBillStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Finance\VendorBill;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\GrnItem;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\PayableService;
use App\Services\Finance\VendorBillService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

/**
 * PO (intra-state, Mumbai Traders): cement 100 Bag @ 400, steel 5 MT @ 62,000, GST 18 %.
 * GRN 1 (approved): cement received 60, rejected 10 → accepted 50; steel 5 accepted.
 * The accountant enters bills; PM → Director (vendor_bills.approve) approves.
 */
beforeEach(function () {
    $this->setUpInventory();
    $this->accountant = $this->createMember($this->company, DefaultRoles::ACCOUNTANT);
    $this->inCompany($this->company, fn () => $this->project->forceFill(['state_code' => '27'])->save());
    $this->po = $this->approvedPo();
    $this->grn = $this->approveGrn($this->makeGrn($this->po, [['60', '10', 'Torn bags'], ['5']]));
    [$this->cementLine, $this->steelLine] = $this->inCompany($this->company, fn () => GrnItem::query()->where('grn_id', $this->grn->id)->orderBy('id')->get()->all());
    $this->gst18 = $this->inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST 18%')->value('id'));
});

function poBillPayload($test, string $cement, string $steel, array $overrides = []): array
{
    return array_replace([
        'bill_type' => 'purchase_order',
        'purchase_order_id' => $test->po->id,
        'vendor_invoice_no' => 'MT/2026/101',
        'vendor_invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'tds_percent' => '1',
        'items' => [
            ['grn_item_id' => $test->cementLine->id, 'quantity' => $cement],
            ['grn_item_id' => $test->steelLine->id, 'quantity' => $steel],
        ],
    ], $overrides);
}

function directBillPayload($test, array $overrides = []): array
{
    return array_replace([
        'bill_type' => 'direct',
        'vendor_id' => $test->vendorThird->id,
        'vendor_invoice_no' => 'PS-778',
        'vendor_invoice_date' => now()->toDateString(),
        'cost_head' => 'equipment',
        'tds_percent' => '2',
        'items' => [
            ['description' => 'Crane hire', 'hsn_sac' => '997313', 'unit_id' => $test->unitId('Day'), 'quantity' => '2', 'rate' => '7500', 'discount_percent' => '0', 'tax_rate_id' => $test->gst18],
            ['description' => 'Operator bata', 'unit_id' => $test->unitId('Day'), 'quantity' => '2', 'rate' => '450.25'],
        ],
    ], $overrides);
}

function storeVendorBill($test, array $payload): VendorBill
{
    $test->actingInCompany($test->accountant, $test->company)->post(route('projects.vendor-bills.store', $test->project), $payload)->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => VendorBill::query()->latest('id')->firstOrFail());
}

function approveVendorBill($test, VendorBill $bill): VendorBill
{
    return $test->inCompany($test->company, function () use ($test, $bill) {
        app(VendorBillService::class)->submit($bill->fresh(), $test->accountant);
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($bill->fresh()->pendingApprovalRequest(), $test->pm);
        $approvals->approve($request, $test->director);

        return $bill->fresh();
    });
}

function ledger($test)
{
    return $test->inCompany($test->company, fn () => ProjectCostEntry::query()->orderBy('id')->get());
}

test('the bill form shows the 3-way match from PO and approved GRN lines', function () {
    $this->actingInCompany($this->accountant, $this->company)
        ->get(route('projects.vendor-bills.create', [$this->project, 'purchase_order_id' => $this->po->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/VendorBills/Form')
            ->where('purchaseOrder.tax_type', 'intra')
            ->has('purchaseOrder.lines', 2)
            ->where('purchaseOrder.lines.0.po_qty', '100.0000')
            ->where('purchaseOrder.lines.0.received_qty', '60.0000')
            ->where('purchaseOrder.lines.0.accepted_qty', '50.0000')
            ->where('purchaseOrder.lines.0.billed_qty', '0.0000')
            ->where('purchaseOrder.lines.0.balance_qty', '50.0000'));
});

test('a PO bill takes rate and GST from the PO and computes exact totals, TDS and net (hand-checked)', function () {
    $bill = storeVendorBill($this, poBillPayload($this, '30', '2'));

    // Cement 30 × 400 = 12,000 + CGST 1,080 + SGST 1,080; steel 2 × 62,000 = 124,000 + 11,160 + 11,160.
    // Subtotal 136,000; GST 24,480; total 160,480; TDS 1 % of taxable = 1,360; net 159,120.
    expect($bill->bill_number)->toBe('VB-PRJ001-0001')
        ->and($bill->vendor_id)->toBe($this->vendorIntra->id)
        ->and($bill->purchase_order_id)->toBe($this->po->id)
        ->and($bill->cost_head)->toBeNull()
        ->and($bill->subtotal)->toBe('136000.00')
        ->and($bill->cgst_amount)->toBe('12240.00')
        ->and($bill->sgst_amount)->toBe('12240.00')
        ->and($bill->igst_amount)->toBe('0.00')
        ->and($bill->total_amount)->toBe('160480.00')
        ->and($bill->tds_amount)->toBe('1360.00')
        ->and($bill->net_payable)->toBe('159120.00');

    $items = $this->inCompany($this->company, fn () => $bill->items()->orderBy('sort_order')->get());
    expect($items[0]->grn_item_id)->toBe($this->cementLine->id)
        ->and($items[0]->rate)->toBe('400.0000')
        ->and($items[0]->amount)->toBe('14160.00');
});

test('billing is capped by the accepted GRN quantity less what other bills already billed', function () {
    $store = fn (array $payload) => $this->actingInCompany($this->accountant, $this->company)->post(route('projects.vendor-bills.store', $this->project), $payload);

    $store(poBillPayload($this, '50.0001', '0'))->assertSessionHasErrors('items');
    $first = storeVendorBill($this, poBillPayload($this, '30', '0'));

    // A draft does not reserve; a submitted bill does.
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.vendor-bills.submit', [$this->project, $first]))->assertSessionHasNoErrors();
    $store(poBillPayload($this, '21', '0', ['vendor_invoice_no' => 'MT/2026/102']))->assertSessionHasErrors('items');
    $second = storeVendorBill($this, poBillPayload($this, '20', '5', ['vendor_invoice_no' => 'MT/2026/102']));

    $this->actingInCompany($this->accountant, $this->company)
        ->get(route('projects.vendor-bills.show', [$this->project, $second]))
        ->assertInertia(fn (Assert $page) => $page->component('Finance/VendorBills/Show')
            ->where('items.0.po_qty', '100.0000')
            ->where('items.0.grn_received_qty', '60.0000')
            ->where('items.0.grn_accepted_qty', '50.0000')
            ->where('items.0.billed_elsewhere', '30.0000'));

    // Only approved GRN lines of the PO are billable.
    $pending = $this->makeGrn($this->po, [['10']]);
    $pendingLine = $this->inCompany($this->company, fn () => GrnItem::query()->where('grn_id', $pending->id)->value('id'));
    $store(poBillPayload($this, '0', '0', ['vendor_invoice_no' => 'MT/2026/103', 'items' => [['grn_item_id' => $pendingLine, 'quantity' => '1']]]))
        ->assertSessionHasErrors('items.0.grn_item_id');
});

test('the vendor invoice number is unique per vendor', function () {
    storeVendorBill($this, poBillPayload($this, '10', '0'));

    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.vendor-bills.store', $this->project), poBillPayload($this, '10', '0'))
        ->assertSessionHasErrors('vendor_invoice_no');

    // The same number from another vendor is fine.
    storeVendorBill($this, directBillPayload($this, ['vendor_invoice_no' => 'MT/2026/101']));
    expect($this->inCompany($this->company, fn () => VendorBill::query()->count()))->toBe(2);
});

test('approving a PO bill posts no cost: material cost comes only from the inventory issue', function () {
    // Issue 10 bags on the BOQ line: material cost 4,000 (moving average 400).
    $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '10', 'boq_item_id' => $this->boqItemId()]]));
    expect(ledger($this))->toHaveCount(1)->and(ledger($this)[0]->amount)->toBe('4000.00');

    $bill = approveVendorBill($this, storeVendorBill($this, poBillPayload($this, '50', '5')));
    expect($bill->status)->toBe(VendorBillStatus::Approved)
        ->and($bill->approved_by)->toBe($this->director->id)
        ->and(ledger($this))->toHaveCount(1)
        ->and(Decimal::sum(ledger($this)->pluck('amount')->all())->toMoney())->toBe('4000.00');

    // Idempotent approval, locked bill.
    $this->inCompany($this->company, fn () => app(VendorBillService::class)->approve($bill->fresh(), $this->director->id));
    expect(ledger($this))->toHaveCount(1);
    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('projects.vendor-bills.update', [$this->project, $bill]), poBillPayload($this, '1', '0'))
        ->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.vendor-bills.destroy', [$this->project, $bill]))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(VendorBillService::class)->update($bill->fresh(), poBillPayload($this, '1', '0'))))
        ->toThrow(Exception::class);
});

test('a direct bill posts each line taxable value once under its explicit cost head', function () {
    $bill = storeVendorBill($this, directBillPayload($this, ['task_id' => null]));

    // Crane 2 × 7,500 = 15,000 + CGST 1,350 + SGST 1,350; bata 2 × 450.25 = 900.50 (no GST).
    // Subtotal 15,900.50; total 18,600.50; TDS 2 % = 318.01; net 18,282.49.
    expect($bill->tax_type->value)->toBe('intra')
        ->and($bill->cost_head)->toBe(CostHead::Equipment)
        ->and($bill->subtotal)->toBe('15900.50')
        ->and($bill->total_amount)->toBe('18600.50')
        ->and($bill->tds_amount)->toBe('318.01')
        ->and($bill->net_payable)->toBe('18282.49')
        ->and(ledger($this))->toHaveCount(0);

    $bill = approveVendorBill($this, $bill);
    $rows = ledger($this);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('amount')->all())->toBe(['15000.00', '900.50'])
        ->and($rows->every(fn ($r) => $r->cost_head === CostHead::Equipment && $r->source_type === 'vendor_bill_item'))->toBeTrue();

    $this->inCompany($this->company, fn () => app(VendorBillService::class)->approve($bill->fresh(), $this->director->id));
    expect(ledger($this))->toHaveCount(2);

    // A direct bill must be classified.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.vendor-bills.store', $this->project), directBillPayload($this, ['vendor_invoice_no' => 'PS-779', 'cost_head' => null]))
        ->assertSessionHasErrors('cost_head');
});

test('a direct bill from another state charges IGST', function () {
    $bill = storeVendorBill($this, directBillPayload($this, ['vendor_id' => $this->vendorInter->id, 'items' => [
        ['description' => 'Testing services', 'unit_id' => $this->unitId('Day'), 'quantity' => '1', 'rate' => '10000.555', 'tax_rate_id' => $this->gst18],
    ]]));

    // 10,000.555 → taxable 10,000.56 (rounded), IGST 18 % = 1,800.10.
    expect($bill->tax_type->value)->toBe('inter')
        ->and($bill->subtotal)->toBe('10000.56')
        ->and($bill->cgst_amount)->toBe('0.00')
        ->and($bill->igst_amount)->toBe('1800.10')
        ->and($bill->total_amount)->toBe('11800.66');
});

test('vendor payments of 40,000 then 60,000 settle a 100,000 bill exactly and post no cost', function () {
    $bill = approveVendorBill($this, storeVendorBill($this, directBillPayload($this, ['tds_percent' => '0', 'items' => [
        ['description' => 'Scaffolding hire', 'unit_id' => $this->unitId('Day'), 'quantity' => '1', 'rate' => '100000'],
    ]])));
    expect($bill->net_payable)->toBe('100000.00');
    $ledger = ledger($this)->count();
    $pay = fn (string $amount, string $allocate) => $this->actingInCompany($this->accountant, $this->company)->post(route('projects.payments.store', $this->project), [
        'party_type' => 'vendor', 'party_id' => $this->vendorThird->id, 'payment_date' => now()->toDateString(), 'mode' => 'cheque',
        'bank_reference' => 'CHQ-'.$amount, 'amount' => $amount, 'allocations' => [['payable_id' => $bill->id, 'amount' => $allocate]],
    ]);
    $approveLatest = fn () => $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.payments.approve', [$this->project, $this->inCompany($this->company, fn () => Payment::query()->latest('id')->firstOrFail())]));

    $pay('40000', '40000')->assertSessionHasNoErrors();
    $approveLatest()->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(VendorBillStatus::PartiallyPaid)
        ->and($bill->fresh()->paid_amount)->toBe('40000.00')
        ->and($this->inCompany($this->company, fn () => app(PayableService::class)->outstanding($bill->fresh())->toMoney()))->toBe('60000.00');

    $pay('60000.01', '60000.01')->assertSessionHasErrors('allocations.0.amount');
    $pay('60000', '60000')->assertSessionHasNoErrors();
    $approveLatest()->assertSessionHasNoErrors();
    expect($bill->fresh()->status)->toBe(VendorBillStatus::Paid)
        ->and($bill->fresh()->paid_amount)->toBe('100000.00')
        ->and(ledger($this)->count())->toBe($ledger)
        ->and(Decimal::sum(ledger($this)->pluck('amount')->all())->toMoney())->toBe('100000.00'); // the bill's own cost only
});

test('vendor bills need vendor_bills permissions and are isolated by company and project', function () {
    $bill = storeVendorBill($this, poBillPayload($this, '10', '0'));

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.vendor-bills.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.vendor-bills.store', $this->project), poBillPayload($this, '1', '0', ['vendor_invoice_no' => 'X1']))->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.vendor-bills.show', [$this->otherProject, $bill]))->assertNotFound();

    // A PO of another project cannot be billed here.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.vendor-bills.store', $this->otherProject), poBillPayload($this, '1', '0', ['vendor_invoice_no' => 'X2']))
        ->assertSessionHasErrors('purchase_order_id');

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.vendor-bills.show', [$this->project, $bill]))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('projects.vendor-bills.submit', [$this->project, $bill]))->assertNotFound();
});

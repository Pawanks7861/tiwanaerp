<?php

use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\RfqStatus;
use App\Enums\Procurement\TaxType;
use App\Events\Procurement\PurchaseOrderApproved;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\PurchaseOrderRevision;
use App\Models\Procurement\Rfq;
use App\Notifications\Procurement\ProcurementNotification;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
});

function poOf($test, int $id): PurchaseOrder
{
    return $test->inCompany($test->company, fn () => PurchaseOrder::query()->with('items')->findOrFail($id));
}

function mrLines($test, MaterialRequest $mr)
{
    return $test->inCompany($test->company, fn () => $mr->items()->orderBy('sort_order')->get()->values());
}

/**
 * Direct PO payload for the approved MR's lines: [[mr line index, qty, rate, discount], ...].
 */
function directPayload($test, MaterialRequest $mr, array $lines, array $extra = []): array
{
    $mrItems = mrLines($test, $mr);
    $gst18 = $test->inCompany($test->company, fn () => TaxRate::query()->where('name', 'GST 18%')->value('id'));

    return array_replace([
        'vendor_id' => $test->vendorIntra->id,
        'direct_justification' => 'Emergency purchase for the raft pour.',
        'po_date' => now()->toDateString(),
        'place_of_supply_state' => '27',
        'items' => array_map(fn ($l) => [
            'material_request_item_id' => $mrItems[$l[0]]->id, 'quantity' => $l[1], 'rate' => $l[2], 'discount_percent' => $l[3] ?? '0', 'tax_rate_id' => $gst18,
        ], $lines),
    ], $extra);
}

test('a purchase order created from the approved comparison carries the quotation at intra-state GST', function () {
    $rfq = $this->sentRfq($this->approvedMr());
    $quotation = $this->quote($rfq, $this->vendorIntra, ['400', '62000'], ['payment_terms' => '45 days']);
    $this->approvedComparison($rfq, $quotation);

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.purchase-order', [$this->project, $rfq]))
        ->assertSessionHasNoErrors();

    $po = $this->inCompany($this->company, fn () => PurchaseOrder::query()->with('items')->firstOrFail());
    $lines = $po->items->sortBy('sort_order')->values();

    // Cement 100 × 400 = 40,000 → CGST/SGST 9 % = 3,600 each; steel 5 × 62,000 = 310,000 → 27,900 each.
    expect($po->po_number)->toBe('PO-PRJ001-0001')
        ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->tax_type)->toBe(TaxType::Intra)
        ->and($po->vendor_quotation_id)->toBe($quotation->id)
        ->and($po->payment_terms)->toBe('45 days')
        ->and($lines[0]->cgst_amount)->toBe('3600.00')
        ->and($lines[0]->sgst_amount)->toBe('3600.00')
        ->and($lines[0]->igst_amount)->toBe('0.00')
        ->and($lines[1]->cgst_amount)->toBe('27900.00')
        ->and($lines[1]->sgst_amount)->toBe('27900.00')
        ->and($lines[0]->hsn_sac)->toBe('2523')
        ->and($po->taxable_amount)->toBe('350000.00')
        ->and($po->cgst_amount)->toBe('31500.00')
        ->and($po->sgst_amount)->toBe('31500.00')
        ->and($po->round_off)->toBe('0.00')
        ->and($po->grand_total)->toBe('413000.00')
        ->and($this->inCompany($this->company, fn () => Rfq::query()->find($rfq->id)->status))->toBe(RfqStatus::Closed);

    // A second PO for the same RFQ is refused.
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.purchase-order', [$this->project, $rfq]))
        ->assertSessionHasErrors('rfq');
});

test('an inter-state vendor is charged IGST only', function () {
    $rfq = $this->sentRfq($this->approvedMr(), [$this->vendorInter]);
    $this->approvedComparison($rfq, $this->quote($rfq, $this->vendorInter, ['400', '62000']));
    $po = $this->poFromRfq($rfq);

    expect($po->tax_type)->toBe(TaxType::Inter)
        ->and($po->vendor_state_code)->toBe('29')
        ->and($po->place_of_supply_state)->toBe('27')
        ->and($po->cgst_amount)->toBe('0.00')
        ->and($po->sgst_amount)->toBe('0.00')
        ->and($po->igst_amount)->toBe('63000.00')
        ->and($po->grand_total)->toBe('413000.00');
});

test('a direct purchase order is calculated on the server to the paisa and ignores forged tax fields', function () {
    $mr = $this->approvedMr();

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), directPayload($this, $mr, [[0, '37', '412.35', '2.5'], [1, '1.255', '61999.99']], [
            'freight_amount' => '1250.75', 'tax_type' => 'inter', 'grand_total' => '1.00', 'round_off' => '99',
        ]))
        ->assertSessionHasNoErrors();

    $po = $this->inCompany($this->company, fn () => PurchaseOrder::query()->with('items')->firstOrFail());
    $lines = $po->items->sortBy('sort_order')->values();

    // Cement: 37 × 412.35 = 15,256.95; 2.5 % = 381.42375 → 381.42; taxable 14,875.53;
    //         9 % = 1,338.7977 → 1,338.80 each; amount 17,553.13.
    // Steel:  1.255 × 61,999.99 = 77,809.98745 → 77,809.99; 9 % = 7,002.8991 → 7,002.90 each; amount 91,815.79.
    // Taxable 92,685.52 + CGST 8,341.70 + SGST 8,341.70 + freight 1,250.75 = 110,619.67 → 110,620.00 (round off +0.33).
    expect($po->tax_type)->toBe(TaxType::Intra)
        ->and($po->direct_justification)->toBe('Emergency purchase for the raft pour.')
        ->and($lines[0]->base_amount)->toBe('15256.95')
        ->and($lines[0]->discount_amount)->toBe('381.42')
        ->and($lines[0]->taxable_amount)->toBe('14875.53')
        ->and($lines[0]->cgst_amount)->toBe('1338.80')
        ->and($lines[0]->amount)->toBe('17553.13')
        ->and($lines[1]->taxable_amount)->toBe('77809.99')
        ->and($lines[1]->sgst_amount)->toBe('7002.90')
        ->and($lines[1]->amount)->toBe('91815.79')
        ->and($po->subtotal)->toBe('93066.94')
        ->and($po->discount_amount)->toBe('381.42')
        ->and($po->taxable_amount)->toBe('92685.52')
        ->and($po->cgst_amount)->toBe('8341.70')
        ->and($po->sgst_amount)->toBe('8341.70')
        ->and($po->igst_amount)->toBe('0.00')
        ->and($po->freight_amount)->toBe('1250.75')
        ->and($po->round_off)->toBe('0.33')
        ->and($po->grand_total)->toBe('110620.00');
});

test('direct purchase orders need a justification, approved MR lines and the purchase.create permission', function () {
    $mr = $this->approvedMr();

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), directPayload($this, $mr, [[0, '10', '400']], ['direct_justification' => 'short']))
        ->assertSessionHasErrors('direct_justification');

    $draft = $this->makeMr();
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), directPayload($this, $draft, [[0, '10', '400']]))
        ->assertSessionHasErrors('items.0.material_request_item_id');

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), directPayload($this, $mr, [[0, '10', '400']]))
        ->assertForbidden();

    expect($this->inCompany($this->company, fn () => PurchaseOrder::query()->count()))->toBe(0);
});

test('approval updates material request ordered quantities, notifies receivers and is idempotent', function () {
    Event::fake([PurchaseOrderApproved::class]);
    Notification::fake();
    $mr = $this->approvedMr();
    $po = $this->inCompany($this->company, fn () => app(PurchaseOrderService::class)->createDirect($this->project, directPayload($this, $mr, [[0, '37', '400']])));

    $this->approvePo($po);

    $lines = mrLines($this, $mr);
    expect(poOf($this, $po->id)->status)->toBe(PurchaseOrderStatus::Approved)
        ->and(poOf($this, $po->id)->approved_by)->toBe($this->director->id)
        ->and($lines[0]->ordered_qty)->toBe('37.0000')
        ->and($lines[1]->ordered_qty)->toBe('0.0000')
        ->and($this->inCompany($this->company, fn () => $mr->fresh()->status))->toBe(MaterialRequestStatus::PartiallyOrdered);
    Event::assertDispatchedTimes(PurchaseOrderApproved::class, 1);

    $this->inCompany($this->company, fn () => app(PurchaseOrderService::class)->markApproved(PurchaseOrder::query()->find($po->id), $this->director->id));
    expect(mrLines($this, $mr)[0]->ordered_qty)->toBe('37.0000');
    Event::assertDispatchedTimes(PurchaseOrderApproved::class, 1);

    // Only 63 bags remain on the MR line.
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), directPayload($this, $mr, [[0, '64', '400']]))
        ->assertSessionHasErrors('items');
});

test('the approval listener notifies the store team', function () {
    Notification::fake();
    $this->approvedPo();

    Notification::assertSentTo($this->storekeeper, ProcurementNotification::class);
});

test('an approved purchase order is locked against forged edits', function () {
    $po = $this->approvedPo();
    $item = poOf($this, $po->id)->items->first();
    $payload = ['po_date' => now()->toDateString(), 'place_of_supply_state' => '27', 'items' => [['id' => $item->id, 'quantity' => '1', 'rate' => '1']]];

    $this->actingInCompany($this->purchaser, $this->company)->put(route('projects.purchase-orders.update', [$this->project, $po]), $payload)->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->put(route('projects.purchase-orders.update', [$this->project, $po]), $payload)->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->delete(route('projects.purchase-orders.destroy', [$this->project, $po]))->assertForbidden();

    $this->inCompany($this->company, function () use ($po, $item) {
        expect(fn () => PurchaseOrder::query()->find($po->id)->forceFill(['grand_total' => '1.00'])->save())->toThrow(ValidationException::class)
            ->and(fn () => PurchaseOrderItem::query()->find($item->id)->forceFill(['rate' => '1'])->save())->toThrow(ValidationException::class);
    });

    expect(poOf($this, $po->id)->grand_total)->toBe('413000.00');
});

test('amending snapshots the approved version, bumps the revision and requires re-approval', function () {
    $po = $this->approvedPo();

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.amend', [$this->project, $po]), ['reason' => 'Reduce cement'])
        ->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.purchase-orders.amend', [$this->project, $po]), ['reason' => 'Reduce cement to 90 bags'])
        ->assertRedirect(route('projects.purchase-orders.edit', [$this->project, $po]));

    $amended = poOf($this, $po->id);
    $revision = $this->inCompany($this->company, fn () => PurchaseOrderRevision::query()->where('purchase_order_id', $po->id)->firstOrFail());
    expect($amended->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($amended->revision_no)->toBe(1)
        ->and($amended->approved_at)->toBeNull()
        ->and($revision->revision_no)->toBe(0)
        ->and($revision->reason)->toBe('Reduce cement to 90 bags')
        ->and(Decimal::of((string) $revision->snapshot['header']['grand_total'])->toMoney())->toBe('413000.00')
        ->and($revision->snapshot['items'])->toHaveCount(2);

    $lines = $amended->items->sortBy('sort_order')->values();
    $gst18 = $this->inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST 18%')->value('id'));
    $this->actingInCompany($this->purchaser, $this->company)
        ->put(route('projects.purchase-orders.update', [$this->project, $po]), [
            'po_date' => now()->toDateString(), 'place_of_supply_state' => '27',
            'items' => [
                ['id' => $lines[0]->id, 'quantity' => '90', 'rate' => '400', 'tax_rate_id' => $gst18],
                ['id' => $lines[1]->id, 'quantity' => '5', 'rate' => '62000', 'tax_rate_id' => $gst18],
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->approvePo($amended);

    // 90 × 400 = 36,000 + 6,480 GST; steel 365,800 → 408,280.
    $reapproved = poOf($this, $po->id);
    expect($reapproved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($reapproved->revision_no)->toBe(1)
        ->and($reapproved->grand_total)->toBe('408280.00')
        ->and($reapproved->po_number)->toBe('PO-PRJ001-0001');

    // The revision snapshot is immutable.
    $this->inCompany($this->company, fn () => PurchaseOrderRevision::query()->find($revision->id)->forceFill(['reason' => 'x'])->save());
    expect($this->inCompany($this->company, fn () => PurchaseOrderRevision::query()->find($revision->id)->reason))->toBe('Reduce cement to 90 bags');
});

test('cancelling needs a reason and the permission, and releases the ordered quantity', function () {
    $po = $this->approvedPo();
    $mr = $this->inCompany($this->company, fn () => MaterialRequest::query()->firstOrFail());

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.cancel', [$this->project, $po]), ['reason' => 'Vendor backed out'])
        ->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.purchase-orders.cancel', [$this->project, $po]), ['reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.purchase-orders.cancel', [$this->project, $po]), ['reason' => 'Vendor backed out'])
        ->assertSessionHasNoErrors();

    $cancelled = poOf($this, $po->id);
    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($cancelled->cancelled_reason)->toBe('Vendor backed out')
        ->and($cancelled->cancelled_by)->toBe($this->admin->id)
        ->and(mrLines($this, $mr)[0]->ordered_qty)->toBe('0.0000')
        ->and($this->inCompany($this->company, fn () => $mr->fresh()->status))->toBe(MaterialRequestStatus::Approved);
});

test('an order with an approved goods receipt cannot be cancelled but can be short-closed', function () {
    $po = $this->approvedPo();
    $this->approveGrn($this->makeGrn($po, [['60']]));

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.purchase-orders.cancel', [$this->project, $po]), ['reason' => 'Vendor backed out'])
        ->assertForbidden();
    $this->inCompany($this->company, function () use ($po) {
        expect(fn () => app(PurchaseOrderService::class)->cancel(PurchaseOrder::query()->find($po->id), 'Forced', $this->admin))
            ->toThrow(ValidationException::class);
    });

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.purchase-orders.close', [$this->project, $po]), ['reason' => 'Balance not required'])
        ->assertSessionHasNoErrors();

    $mr = $this->inCompany($this->company, fn () => MaterialRequest::query()->firstOrFail());
    expect(poOf($this, $po->id)->status)->toBe(PurchaseOrderStatus::Closed)
        ->and(mrLines($this, $mr)[0]->ordered_qty)->toBe('60.0000');
});

test('the PDF is generated from stored values for users with the export permission', function () {
    $po = $this->approvedPo();

    $response = $this->actingInCompany($this->purchaser, $this->company)->get(route('projects.purchase-orders.pdf', [$this->project, $po]));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('PO-PRJ001-0001.pdf');

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.purchase-orders.pdf', [$this->project, $po]))->assertForbidden();
});

test('the show page lists totals, revisions and receipt actions', function () {
    $po = $this->approvedPo();

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.purchase-orders.show', [$this->project, $po]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/PurchaseOrders/Show')
            ->where('order.grand_total', '413000.00')
            ->where('order.tax_type', 'intra')
            ->has('items', 2)
            ->where('can.receive', true)
            ->where('can.update', false)
            ->missing('order.vendor.bank_account_no'));
});

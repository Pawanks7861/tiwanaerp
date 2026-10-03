<?php

use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\ProjectRole;
use App\Events\Procurement\GrnApproved;
use App\Models\Core\Role;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Notifications\Procurement\ProcurementNotification;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
    $this->po = $this->approvedPo();
    $this->poLines = $this->inCompany($this->company, fn () => $this->po->items()->orderBy('sort_order')->get()->values());
});

/**
 * @param  array<int, array{0: string, 1?: string, 2?: string}>  $quantities  by PO line index
 */
function grnPayload($test, array $quantities, array $extra = []): array
{
    return $extra + [
        'purchase_order_id' => $test->po->id,
        'warehouse_id' => $test->siteStore()->id,
        'receipt_date' => now()->toDateString(),
        'vendor_invoice_no' => 'INV-77',
        'items' => collect($test->poLines)->map(fn ($line, $i) => [
            'purchase_order_item_id' => $line->id,
            'received_qty' => $quantities[$i][0] ?? '',
            'rejected_qty' => $quantities[$i][1] ?? '',
            'rejection_reason' => $quantities[$i][2] ?? null,
        ])->all(),
    ];
}

function grnOf($test, ?int $id = null): Grn
{
    return $test->inCompany($test->company, fn () => $id ? Grn::query()->with('items')->findOrFail($id) : Grn::query()->with('items')->latest('id')->firstOrFail());
}

function poState($test): PurchaseOrder
{
    return $test->inCompany($test->company, fn () => PurchaseOrder::query()->with('items')->findOrFail($test->po->id));
}

function mrState($test): MaterialRequest
{
    return $test->inCompany($test->company, fn () => MaterialRequest::query()->with('items')->firstOrFail());
}

test('the GRN form is pre-filled from the purchase order with the open quantities and tolerance', function () {
    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.grns.create', [$this->project, 'purchase_order' => $this->po->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Grns/Form')
            ->where('purchaseOrder.po_number', 'PO-PRJ001-0001')
            ->has('lines', 2)
            ->where('lines.0.ordered_qty', '100.0000')
            ->where('lines.0.remaining_qty', '100.0000')
            ->where('lines.0.max_acceptable_qty', '100.0000')
            ->where('tolerance', '0.0000'));
});

test('a partial receipt with rejections computes accepted quantities and updates the PO and MR on approval', function () {
    Event::fake([GrnApproved::class]);
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['60', '5', 'Torn bags']], ['accepted_qty' => '999', 'status' => 'approved']))
        ->assertSessionHasNoErrors();

    $grn = grnOf($this);
    expect($grn->grn_number)->toBe('GRN-PRJ001-0001')
        ->and($grn->status)->toBe(GrnStatus::Draft)
        ->and($grn->items)->toHaveCount(1)
        ->and($grn->items[0]->received_qty)->toBe('60.0000')
        ->and($grn->items[0]->rejected_qty)->toBe('5.0000')
        ->and($grn->items[0]->accepted_qty)->toBe('55.0000')
        ->and($grn->items[0]->rejection_reason)->toBe('Torn bags')
        ->and($grn->items[0]->rate)->toBe('400.0000');

    // Nothing moves until the GRN is approved.
    expect(poState($this)->items->sortBy('sort_order')->first()->received_qty)->toBe('0.0000');

    $store->post(route('projects.grns.submit', [$this->project, $grn]))->assertSessionHasNoErrors();
    $request = $this->inCompany($this->company, fn () => grnOf($this, $grn->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();

    $po = poState($this);
    $mr = mrState($this);
    expect(grnOf($this, $grn->id)->status)->toBe(GrnStatus::Approved)
        ->and($po->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($po->items->sortBy('sort_order')->first()->received_qty)->toBe('55.0000')
        ->and($mr->items->sortBy('sort_order')->first()->received_qty)->toBe('55.0000')
        ->and($mr->status)->toBe(MaterialRequestStatus::Ordered);
    Event::assertDispatchedTimes(GrnApproved::class, 1);
});

test('GRN approval notifies the material request requester and the project manager', function () {
    Notification::fake();

    $this->approveGrn($this->makeGrn($this->po, [['10']]));

    Notification::assertSentTo([$this->engineer, $this->pm], ProcurementNotification::class);
    Notification::assertNotSentTo($this->billing, ProcurementNotification::class);
});

test('a second receipt completes the order and the material request', function () {
    $this->approveGrn($this->makeGrn($this->po, [['60', '5', 'Torn bags']]));

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.grns.create', [$this->project, 'purchase_order' => $this->po->id]))
        ->assertInertia(fn (Assert $page) => $page->where('lines.0.remaining_qty', '45.0000')->where('lines.0.received_qty', '55.0000'));

    $this->approveGrn($this->makeGrn($this->po, [['45'], ['5']]));

    $po = poState($this);
    expect($po->status)->toBe(PurchaseOrderStatus::Received)
        ->and($po->items->sortBy('sort_order')->pluck('received_qty')->values()->all())->toBe(['100.0000', '5.0000'])
        ->and(mrState($this)->status)->toBe(MaterialRequestStatus::Received)
        ->and($this->inCompany($this->company, fn () => Grn::query()->count()))->toBe(2);

    // A fully received order accepts no more receipts.
    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.grns.store', $this->project), grnPayload($this, [['1']]))
        ->assertForbidden();
});

test('a platform super admin is only offered the actions the order status allows', function () {
    $this->approveGrn($this->makeGrn($this->po, [['100'], ['5']]));
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->actingInCompany($this->admin, $this->company)
        ->get(route('projects.purchase-orders.show', [$this->project, $this->po]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.amend', false)
            ->where('can.cancel', false)
            ->where('can.close', true)
            ->where('can.update', false)
            ->where('can.receive', false));

    $this->actingInCompany($this->admin, $this->company)
        ->get(route('projects.rfqs.show', [$this->project, $this->po->rfq_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manage_vendors', false)
            ->where('can.close', false)
            ->where('can.cancel', false));

    $quotationId = $this->inCompany($this->company, fn () => $this->po->rfq->quotations()->value('id'));
    $this->actingInCompany($this->admin, $this->company)
        ->get(route('projects.rfqs.quotations.edit', [$this->project, $this->po->rfq_id, $quotationId]))
        ->assertForbidden();
});

test('over-receipt is blocked at the default 0 % tolerance and allowed within a configured tolerance', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['100.0001']]))->assertSessionHasErrors('items.0.received_qty');
    // Rejected quantity does not count towards the ordered quantity.
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['103', '3', 'Wet']]))->assertSessionHasNoErrors();
    $this->inCompany($this->company, fn () => app(GrnService::class)->delete(grnOf($this)));

    $this->actingInCompany($this->storekeeper, $this->company)
        ->put(route('admin.company.procurement'), ['grn_tolerance_percent' => '5'])
        ->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)
        ->put(route('admin.company.procurement'), ['grn_tolerance_percent' => '5'])
        ->assertSessionHasNoErrors();

    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['105.0001']]))->assertSessionHasErrors('items.0.received_qty');
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['105']]))->assertSessionHasNoErrors();
    $grn = grnOf($this);
    $store->post(route('projects.grns.submit', [$this->project, $grn]))->assertSessionHasNoErrors();

    // Submitted GRNs count too: nothing more can be accepted on the cement line.
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['1']]))->assertSessionHasErrors('items.0.received_qty');

    $request = $this->inCompany($this->company, fn () => grnOf($this, $grn->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();
    expect(poState($this)->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and(poState($this)->items->sortBy('sort_order')->first()->received_qty)->toBe('105.0000');
});

test('receipt quantities are validated', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['10', '11', 'Broken']]))->assertSessionHasErrors('items.0.rejected_qty');
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['10', '2']]))->assertSessionHasErrors('items.0.rejection_reason');
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['-1']]))->assertSessionHasErrors('items.0.received_qty');
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, []))->assertSessionHasErrors('items');
    $store->post(route('projects.grns.store', $this->project), grnPayload($this, [['10']], ['receipt_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('receipt_date');

    $foreignLine = grnPayload($this, [['10']]);
    $foreignLine['items'][0]['purchase_order_item_id'] = 999999;
    $store->post(route('projects.grns.store', $this->project), $foreignLine)->assertSessionHasErrors('items.0.purchase_order_item_id');

    expect($this->inCompany($this->company, fn () => Grn::query()->count()))->toBe(0);
});

test('an approved GRN is immutable and approving it again changes nothing', function () {
    Event::fake([GrnApproved::class]);
    $grn = $this->approveGrn($this->makeGrn($this->po, [['60']]));
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->put(route('projects.grns.update', [$this->project, $grn]), grnPayload($this, [['1']]))->assertForbidden();
    $store->delete(route('projects.grns.destroy', [$this->project, $grn]))->assertForbidden();
    $store->post(route('projects.grns.submit', [$this->project, $grn]))->assertForbidden();

    $this->inCompany($this->company, function () use ($grn) {
        $fresh = Grn::query()->find($grn->id);
        expect(fn () => $fresh->forceFill(['vendor_invoice_no' => 'forged'])->save())->toThrow(ValidationException::class)
            ->and(fn () => GrnItem::query()->where('grn_id', $grn->id)->first()->forceFill(['accepted_qty' => '1'])->save())->toThrow(ValidationException::class);

        app(GrnService::class)->markApproved($fresh, $this->pm->id);
    });

    expect(poState($this)->items->sortBy('sort_order')->first()->received_qty)->toBe('60.0000');
    Event::assertDispatchedTimes(GrnApproved::class, 1);
});

test('goods cannot be received against an unapproved order, and an order with a pending GRN cannot be cancelled', function () {
    $mrLineId = $this->inCompany($this->company, fn () => $this->approvedMr()->items()->value('id'));
    $draftPo = $this->inCompany($this->company, fn () => app(PurchaseOrderService::class)->createDirect($this->project, [
        'vendor_id' => $this->vendorThird->id, 'direct_justification' => 'Spot purchase of extra cement.', 'po_date' => now()->toDateString(),
        'place_of_supply_state' => '27',
        'items' => [['material_request_item_id' => $mrLineId, 'quantity' => '5', 'rate' => '400']],
    ]));

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.grns.store', $this->project), ['purchase_order_id' => $draftPo->id] + grnPayload($this, [['1']]))
        ->assertForbidden();
    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.grns.create', [$this->project, 'purchase_order' => $draftPo->id]))
        ->assertForbidden();

    $grn = $this->makeGrn($this->po, [['10']]);
    $this->inCompany($this->company, fn () => app(GrnService::class)->submit($grn->fresh(), $this->storekeeper));
    $this->inCompany($this->company, function () {
        expect(fn () => app(PurchaseOrderService::class)->cancel(PurchaseOrder::query()->find($this->po->id), 'Vendor backed out', $this->admin))
            ->toThrow(ValidationException::class);
    });
});

test('rates are hidden from users who cannot view purchase orders', function () {
    $grn = $this->approveGrn($this->makeGrn($this->po, [['60']]));
    $role = Role::query()->create(['team_id' => $this->company->id, 'name' => 'Gate Keeper', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissionNames(['dashboard.view', 'projects.view', 'grn.view']);
    $gatekeeper = $this->createMember($this->company, 'Gate Keeper');
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->project, $gatekeeper->id, ProjectRole::Store));

    $this->actingInCompany($gatekeeper, $this->company)
        ->get(route('projects.grns.show', [$this->project, $grn]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Grns/Show')
            ->where('items.0.rate', null)
            ->where('items.0.accepted_qty', '60.0000')
            ->where('can.view_rates', false)
            ->where('grn.purchase_order.url', null)
            ->missing('grn.vendor.bank_account_no'));

    $this->actingInCompany($gatekeeper, $this->company)->get(route('projects.purchase-orders.show', [$this->project, $this->po]))->assertForbidden();
});

test('the GRN index lists receipts and receivable orders', function () {
    $this->makeGrn($this->po, [['10']]);

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.grns.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Grns/Index')
            ->has('grns.data', 1)
            ->where('grns.data.0.po_number', 'PO-PRJ001-0001')
            ->has('receivable', 1)
            ->where('can.create', true));
});

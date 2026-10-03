<?php

use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\RfqStatus;
use App\Enums\ProjectRole;
use App\Models\Masters\Vendor;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqVendor;
use App\Services\Projects\ProjectService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
});

function rfqOf($test, ?int $id = null): Rfq
{
    return $test->inCompany($test->company, fn () => $id ? Rfq::query()->findOrFail($id) : Rfq::query()->latest('id')->firstOrFail());
}

function rfqPayload($test, $mr, array $quantities, array $vendorIds, array $extra = []): array
{
    $lines = $test->inCompany($test->company, fn () => $mr->items()->orderBy('sort_order')->get())->values();

    return $extra + [
        'title' => 'Cement and steel',
        'rfq_date' => now()->toDateString(),
        'due_date' => now()->addDays(3)->toDateString(),
        'items' => collect($quantities)->map(fn ($qty, $i) => ['material_request_item_id' => $lines[$i]->id, 'quantity' => $qty])->values()->all(),
        'vendor_ids' => $vendorIds,
    ];
}

test('an RFQ is created from approved material request lines and offers the remaining quantity', function () {
    $mr = $this->approvedMr();
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->get(route('projects.rfqs.create', [$this->project, 'material_request' => $mr->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Rfqs/Form')
            ->where('preselect', $mr->id)
            ->has('lines', 2)
            ->where('lines.0.remaining_qty', '100.0000'));

    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['60', '5'], [$this->vendorIntra->id, $this->vendorInter->id]))
        ->assertSessionHasNoErrors();

    $rfq = rfqOf($this);
    expect($rfq->rfq_number)->toBe('RFQ-PRJ001-0001')
        ->and($rfq->status)->toBe(RfqStatus::Draft)
        ->and($this->inCompany($this->company, fn () => $rfq->vendors()->count()))->toBe(2);

    // The open RFQ reserves 60 bags; only 40 remain for another RFQ.
    $as->get(route('projects.rfqs.create', $this->project))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 1)->where('lines.0.remaining_qty', '40.0000'));
});

test('over-procurement across RFQs is blocked', function () {
    $mr = $this->approvedMr();
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['100.0001'], [$this->vendorIntra->id]))
        ->assertSessionHasErrors('items.0.quantity');
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['70'], [$this->vendorIntra->id]))->assertSessionHasNoErrors();
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['31'], [$this->vendorInter->id]))->assertSessionHasErrors('items.0.quantity');
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['30'], [$this->vendorInter->id]))->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => Rfq::query()->count()))->toBe(2);
});

test('only approved material request lines of this project can be quoted', function () {
    $draft = $this->makeMr();
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $draft, ['10'], [$this->vendorIntra->id]))
        ->assertSessionHasErrors('items.0.material_request_item_id');

    $mall = $this->makeTeamProject('Mall');
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($mall, $this->purchaser->id, ProjectRole::Purchase));
    $mr = $this->approvedMr();
    $as->post(route('projects.rfqs.store', $mall), rfqPayload($this, $mr, ['10'], [$this->vendorIntra->id]))
        ->assertSessionHasErrors('items.0.material_request_item_id');
});

test('vendors must be active company vendors and can be invited only once', function () {
    $mr = $this->approvedMr();
    $as = $this->actingInCompany($this->purchaser, $this->company);
    $inactive = $this->inCompany($this->company, fn () => Vendor::query()->create(['code' => 'V009', 'name' => 'Dormant', 'state_code' => '27', 'is_active' => false]));
    $foreign = $this->inCompany($this->createCompany(), fn () => Vendor::query()->create(['code' => 'F001', 'name' => 'Foreign', 'state_code' => '27']));

    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['10'], [$this->vendorIntra->id, $this->vendorIntra->id]))->assertSessionHasErrors('vendor_ids.1');
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['10'], [$inactive->id]))->assertSessionHasErrors('vendor_ids.0');
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['10'], [$foreign->id]))->assertSessionHasErrors('vendor_ids.0');

    expect($this->inCompany($this->company, fn () => Rfq::query()->count()))->toBe(0);
});

test('sending requires items and vendors and locks the RFQ items', function () {
    $mr = $this->approvedMr();
    $rfq = $this->sentRfq($mr, [], send: false);
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->post(route('projects.rfqs.send', [$this->project, $rfq]))->assertSessionHasErrors('rfq');

    $as->put(route('projects.rfqs.vendors', [$this->project, $rfq]), ['vendor_ids' => [$this->vendorIntra->id, $this->vendorThird->id]])->assertSessionHasNoErrors();
    $as->post(route('projects.rfqs.send', [$this->project, $rfq]))->assertSessionHasNoErrors();

    $sent = rfqOf($this, $rfq->id);
    expect($sent->status)->toBe(RfqStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull()
        ->and($this->inCompany($this->company, fn () => RfqVendor::query()->where('rfq_id', $rfq->id)->pluck('status')->map->value->unique()->all()))->toBe(['sent']);

    $as->put(route('projects.rfqs.update', [$this->project, $rfq]), rfqPayload($this, $mr, ['1'], []))->assertForbidden();
    $as->delete(route('projects.rfqs.destroy', [$this->project, $rfq]))->assertForbidden();
});

test('a quoted vendor cannot be removed from the RFQ', function () {
    $rfq = $this->sentRfq($this->approvedMr());
    $this->quote($rfq, $this->vendorIntra, ['400', '62000']);

    $this->actingInCompany($this->purchaser, $this->company)
        ->put(route('projects.rfqs.vendors', [$this->project, $rfq]), ['vendor_ids' => [$this->vendorInter->id]])
        ->assertSessionHasErrors('vendor_ids');
});

test('cancelling an RFQ releases its reserved quantities', function () {
    $mr = $this->approvedMr();
    $rfq = $this->sentRfq($mr);
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->post(route('projects.rfqs.cancel', [$this->project, $rfq]), ['reason' => ''])->assertSessionHasErrors('reason');
    $as->post(route('projects.rfqs.cancel', [$this->project, $rfq]), ['reason' => 'Prices revised'])->assertSessionHasNoErrors();

    expect(rfqOf($this, $rfq->id)->status)->toBe(RfqStatus::Cancelled);
    $as->get(route('projects.rfqs.create', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('lines.0.remaining_qty', '100.0000'));
    expect($this->inCompany($this->company, fn () => $mr->fresh()->status))->toBe(MaterialRequestStatus::Approved);
});

test('site engineers cannot create or send RFQs', function () {
    $mr = $this->approvedMr();
    $rfq = $this->sentRfq($mr, send: false);
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->get(route('projects.rfqs.index', $this->project))->assertForbidden();
    $as->post(route('projects.rfqs.store', $this->project), rfqPayload($this, $mr, ['1'], [$this->vendorIntra->id]))->assertForbidden();
    $as->post(route('projects.rfqs.send', [$this->project, $rfq]))->assertForbidden();
});

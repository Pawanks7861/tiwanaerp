<?php

use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\Rfq;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
    $this->po = $this->approvedPo();
    $this->grn = $this->makeGrn($this->po, [['10']]);
    $this->mr = $this->inCompany($this->company, fn () => MaterialRequest::query()->firstOrFail());
    $this->rfq = $this->inCompany($this->company, fn () => Rfq::query()->firstOrFail());
});

/**
 * Every procurement page of the fixture documents, under the given project.
 *
 * @return list<string>
 */
function procurementUrls($test, $project): array
{
    return [
        route('projects.material-requests.index', $project),
        route('projects.material-requests.show', [$project, $test->mr]),
        route('projects.rfqs.index', $project),
        route('projects.rfqs.show', [$project, $test->rfq]),
        route('projects.rfqs.comparison', [$project, $test->rfq]),
        route('projects.purchase-orders.index', $project),
        route('projects.purchase-orders.show', [$project, $test->po]),
        route('projects.purchase-orders.pdf', [$project, $test->po]),
        route('projects.grns.index', $project),
        route('projects.grns.show', [$project, $test->grn]),
    ];
}

test('users of another company get 404 for every procurement document', function () {
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    foreach (procurementUrls($this, $this->project) as $url) {
        $this->actingInCompany($outsider, $other)->get($url)->assertNotFound();
    }

    $this->actingInCompany($outsider, $other)
        ->post(route('projects.purchase-orders.cancel', [$this->project, $this->po]), ['reason' => 'Hostile cancel'])
        ->assertNotFound();
    $this->actingInCompany($outsider, $other)
        ->post(route('projects.grns.submit', [$this->project, $this->grn]))
        ->assertNotFound();
});

test('documents cannot be reached through another project of the same company', function () {
    $mall = $this->makeTeamProject('Mall');
    $urls = procurementUrls($this, $mall);

    foreach ([$urls[1], $urls[3], $urls[4], $urls[6], $urls[7], $urls[9]] as $url) {
        $this->actingInCompany($this->admin, $this->company)->get($url)->assertNotFound();
    }

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.grns.store', $mall), ['purchase_order_id' => $this->po->id, 'receipt_date' => now()->toDateString(), 'items' => [['purchase_order_item_id' => 1, 'received_qty' => '1']]])
        ->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.grns.store', $mall), ['purchase_order_id' => $this->po->id, 'receipt_date' => now()->toDateString(), 'items' => [['purchase_order_item_id' => 1, 'received_qty' => '1']]])
        ->assertNotFound();
});

test('company members who are not on the project team are forbidden', function () {
    $stranger = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);

    foreach (procurementUrls($this, $this->project) as $url) {
        $this->actingInCompany($stranger, $this->company)->get($url)->assertForbidden();
    }

    $this->actingInCompany($stranger, $this->company)
        ->post(route('projects.material-requests.store', $this->project), $this->mrPayload())
        ->assertForbidden();
});

test('foreign master IDs are rejected on every form', function () {
    $other = $this->createCompany();
    [$material, $vendor] = $this->inCompany($other, fn () => [
        Material::query()->create(['code' => 'F-CEM', 'name' => 'Foreign cement', 'unit_id' => Unit::query()->value('id')]),
        Vendor::query()->create(['code' => 'F-V', 'name' => 'Foreign vendor', 'state_code' => '27']),
    ]);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.material-requests.store', $this->project), $this->mrPayload(['items' => [
            ['material_id' => $material->id, 'unit_id' => $this->cement->unit_id, 'quantity' => '1'],
        ]]))
        ->assertSessionHasErrors('items.0.material_id');

    $mr = $this->approvedMr();
    $mrLine = $this->inCompany($this->company, fn () => $mr->items()->value('id'));

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.store', $this->project), [
            'rfq_date' => now()->toDateString(), 'items' => [['material_request_item_id' => $mrLine, 'quantity' => '1']], 'vendor_ids' => [$vendor->id],
        ])
        ->assertSessionHasErrors('vendor_ids.0');

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.purchase-orders.store', $this->project), [
            'vendor_id' => $vendor->id, 'direct_justification' => 'Trying a foreign vendor.', 'po_date' => now()->toDateString(),
            'place_of_supply_state' => '27', 'items' => [['material_request_item_id' => $mrLine, 'quantity' => '1', 'rate' => '1']],
        ])
        ->assertSessionHasErrors('vendor_id');
});

test('vendor bank details never reach procurement pages', function () {
    $pages = [
        [route('projects.purchase-orders.show', [$this->project, $this->po]), 'order.vendor'],
        [route('projects.grns.show', [$this->project, $this->grn]), 'grn.vendor'],
    ];

    foreach ($pages as [$url, $path]) {
        $this->actingInCompany($this->purchaser, $this->company)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("{$path}.code", 'V001')
                ->missing("{$path}.bank_name")
                ->missing("{$path}.bank_account_no")
                ->missing("{$path}.bank_ifsc"));
    }

    $this->actingInCompany($this->purchaser, $this->company)
        ->get(route('projects.rfqs.show', [$this->project, $this->rfq]))
        ->assertInertia(fn (Assert $page) => $page->missing('vendors.0.vendor.bank_account_no'));
});

test('project members without procurement permissions are forbidden', function () {
    foreach (procurementUrls($this, $this->project) as $url) {
        $this->actingInCompany($this->billing, $this->company)->get($url)->assertForbidden();
    }
});

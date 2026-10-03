<?php

use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = $this->createCompany();
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
});

function masterAdmin($test)
{
    return $test->actingInCompany($test->admin, $test->company);
}

test('every master index renders for an admin', function (string $slug) {
    masterAdmin($this)->get(route('masters.index', $slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Masters/Index', false)
            ->where('definition.slug', $slug)
            ->has('records.data'));
})->with(['items', 'units', 'categories', 'tax-rates', 'vendors', 'subcontractors', 'clients', 'labour-trades', 'equipment-types', 'warehouses', 'expense-categories']);

test('unknown masters are not found', function () {
    masterAdmin($this)->get('/masters/unknown-thing')->assertNotFound();
});

test('vendor codes are generated when left blank and must be unique within the company', function () {
    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Shree Cement'])->assertSessionHasNoErrors();
    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Punjab Steel', 'code' => ''])->assertSessionHasNoErrors();
    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Copy', 'code' => 'ven-0001'])->assertSessionHasErrors('code');

    expect($this->inCompany($this->company, fn () => Vendor::query()->orderBy('id')->pluck('code')->all()))->toBe(['VEN-0001', 'VEN-0002']);

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)
        ->post(route('masters.store', 'vendors'), ['name' => 'Same code elsewhere', 'code' => 'VEN-0001'])
        ->assertSessionHasNoErrors();
});

test('state and PAN are derived from the GSTIN and must match it', function () {
    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Ludhiana Traders', 'gstin' => '03aaact1234a1z5'])
        ->assertSessionHasNoErrors();

    $vendor = $this->inCompany($this->company, fn () => Vendor::query()->sole());
    expect($vendor->gstin)->toBe('03AAACT1234A1Z5')
        ->and($vendor->state_code->value ?? $vendor->state_code)->toBe('03')
        ->and($vendor->pan)->toBe('AAACT1234A');

    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Wrong state', 'gstin' => '03AAACT1234A1Z5', 'state_code' => '27'])
        ->assertSessionHasErrors('state_code');
    masterAdmin($this)->post(route('masters.store', 'vendors'), ['name' => 'Bad GSTIN', 'gstin' => '03AAACT1234'])
        ->assertSessionHasErrors('gstin');
});

test('CGST, SGST and IGST are derived from the GST rate on the server', function () {
    masterAdmin($this)->post(route('masters.store', 'tax-rates'), [
        'name' => 'GST 3%', 'rate' => '3', 'cgst_rate' => '99', 'igst_rate' => '99',
    ])->assertSessionHasNoErrors();

    $rate = $this->inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST 3%')->sole());
    expect($rate->rate)->toBe('3.0000')
        ->and($rate->cgst_rate)->toBe('1.5000')
        ->and($rate->sgst_rate)->toBe('1.5000')
        ->and($rate->igst_rate)->toBe('3.0000');
});

test('items get a material code and a unit used by an item cannot be deleted', function () {
    $unit = $this->inCompany($this->company, fn () => Unit::query()->where('symbol', 'Bag')->sole());

    masterAdmin($this)->post(route('masters.store', 'items'), [
        'name' => 'OPC Cement 53 Grade', 'item_type' => 'material', 'unit_id' => $unit->id, 'standard_rate' => '385.50',
    ])->assertSessionHasNoErrors();

    $item = $this->inCompany($this->company, fn () => Material::query()->sole());
    expect($item->code)->toBe('ITM-00001')->and($item->standard_rate)->toBe('385.5000');

    masterAdmin($this)->delete(route('masters.destroy', ['units', $unit->id]))->assertSessionHasErrors();
    expect($this->inCompany($this->company, fn () => Unit::query()->whereKey($unit->id)->exists()))->toBeTrue();

    $unused = $this->inCompany($this->company, fn () => Unit::query()->where('symbol', 'Set')->sole());
    masterAdmin($this)->delete(route('masters.destroy', ['units', $unused->id]))->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Unit::query()->whereKey($unused->id)->exists()))->toBeFalse();
});

test('a category cannot be made its own ancestor', function () {
    masterAdmin($this)->post(route('masters.store', 'categories'), ['code' => 'CIV', 'name' => 'Civil'])->assertSessionHasNoErrors();
    $parent = $this->inCompany($this->company, fn () => MaterialCategory::query()->sole());
    masterAdmin($this)->post(route('masters.store', 'categories'), ['code' => 'CEM', 'name' => 'Cement', 'parent_id' => $parent->id])->assertSessionHasNoErrors();
    $child = $this->inCompany($this->company, fn () => MaterialCategory::query()->where('code', 'CEM')->sole());

    masterAdmin($this)->put(route('masters.update', ['categories', $parent->id]), ['code' => 'CIV', 'name' => 'Civil', 'parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');
    masterAdmin($this)->put(route('masters.update', ['categories', $parent->id]), ['code' => 'CIV', 'name' => 'Civil', 'parent_id' => $parent->id])
        ->assertSessionHasErrors('parent_id');
});

test('master permissions are enforced per action', function () {
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->get(route('masters.index', 'items'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'update' => false, 'delete' => false]));
    $as->post(route('masters.store', 'vendors'), ['name' => 'Not allowed'])->assertForbidden();

    $unit = $this->inCompany($this->company, fn () => Unit::query()->first());
    $as->put(route('masters.update', ['units', $unit->id]), ['name' => 'X', 'symbol' => 'X'])->assertForbidden();
    $as->delete(route('masters.destroy', ['units', $unit->id]))->assertForbidden();
    $as->get(route('admin.users.index'))->assertForbidden();
});

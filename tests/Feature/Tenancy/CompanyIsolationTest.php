<?php

use App\Exceptions\NoCompanyContextException;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Core\FinancialYear;
use App\Models\Core\Role;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Core\CompanyService;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->companyA = $this->createCompany(['name' => 'Alpha Builders']);
    $this->companyB = $this->createCompany(['name' => 'Beta Infra']);
    $this->adminA = $this->createMember($this->companyA, DefaultRoles::COMPANY_ADMIN);
    $this->adminB = $this->createMember($this->companyB, DefaultRoles::COMPANY_ADMIN);

    $this->vendorB = $this->inCompany($this->companyB, fn () => Vendor::query()->create(['code' => 'VEN-B1', 'name' => 'Beta Cement', 'is_active' => true]));
    $this->projectB = $this->inCompany($this->companyB, fn () => Project::factory()->create());
});

test('tenant queries return nothing without a company context (fail closed)', function () {
    expect(app(CurrentCompany::class)->has())->toBeFalse()
        ->and(Vendor::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0);
});

test('creating a tenant record without a company context is refused', function () {
    Vendor::query()->create(['code' => 'X', 'name' => 'Orphan']);
})->throws(NoCompanyContextException::class);

test('each company sees only its own records', function () {
    $this->inCompany($this->companyA, function () {
        Vendor::query()->create(['code' => 'VEN-A1', 'name' => 'Alpha Steel']);

        expect(Vendor::query()->pluck('code')->all())->toBe(['VEN-A1'])
            ->and(Project::query()->count())->toBe(0);
    });
});

test('a record cannot be moved to another company', function () {
    $this->inCompany($this->companyB, function () {
        $this->vendorB->company_id = $this->companyA->id;
        $this->vendorB->save();
    });
})->throws(LogicException::class);

test('changing the URL to another company\'s master record returns 404', function () {
    $as = $this->actingInCompany($this->adminA, $this->companyA);

    $as->get(route('masters.show', ['vendors', $this->vendorB->id]))->assertNotFound();
    $as->put(route('masters.update', ['vendors', $this->vendorB->id]), ['name' => 'Hacked'])->assertNotFound();
    $as->delete(route('masters.destroy', ['vendors', $this->vendorB->id]))->assertNotFound();

    expect(Vendor::withoutGlobalScopes()->find($this->vendorB->id)->name)->toBe('Beta Cement');
});

test('changing the URL to another company\'s project returns 404', function () {
    $as = $this->actingInCompany($this->adminA, $this->companyA);

    $as->get(route('projects.show', $this->projectB))->assertNotFound();
    $as->get(route('projects.sites.index', $this->projectB))->assertNotFound();
    $as->put(route('projects.update', $this->projectB), ['name' => 'Hacked'])->assertNotFound();
});

test('a company_id in the request payload is ignored', function () {
    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('masters.store', 'vendors'), [
            'name' => 'Payload Vendor',
            'company_id' => $this->companyB->id,
        ])
        ->assertSessionHasNoErrors();

    $vendor = Vendor::withoutGlobalScopes()->where('name', 'Payload Vendor')->sole();
    expect($vendor->company_id)->toBe($this->companyA->id);
});

test('foreign keys pointing at another company\'s records are rejected', function () {
    $unitB = $this->inCompany($this->companyB, fn () => Unit::query()->firstOrFail());

    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('masters.store', 'items'), [
            'name' => 'Cement OPC 53',
            'item_type' => 'material',
            'unit_id' => $unitB->id,
        ])
        ->assertSessionHasErrors('unit_id');
});

test('another company\'s attachment cannot be downloaded', function () {
    Storage::fake('private');

    $attachment = $this->inCompany($this->companyB, fn () => app(AttachmentService::class)
        ->store($this->vendorB, UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%test\n")));

    $this->actingInCompany($this->adminA, $this->companyA)
        ->get(route('attachments.download', $attachment->id))
        ->assertNotFound();
});

test('a session pointing at a company the user does not belong to falls back to their own company', function () {
    $this->actingInCompany($this->adminA, $this->companyB)->get(route('masters.index', 'vendors'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('company.current.id', $this->companyA->id));
});

test('users can switch only to companies they belong to', function () {
    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('company.switch'), ['company_id' => $this->companyB->id])
        ->assertSessionHasErrors('company_id');

    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('company.switch'), ['company_id' => $this->companyB->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('current_company_id', $this->companyB->id);
});

test('roles are scoped per company: admin in A has no admin rights in B', function () {
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->adminA, $this->companyA)->get(route('admin.users.index'))->assertOk();
    $this->actingInCompany($this->adminA, $this->companyB)->get(route('admin.users.index'))->assertForbidden();
});

test('a user without any company membership is refused', function () {
    $loner = User::factory()->create();

    $this->actingAs($loner)->get(route('dashboard'))->assertForbidden();
});

test('a deactivated membership removes access to that company', function () {
    $this->adminA->memberships()->where('company_id', $this->companyA->id)->update(['is_active' => false]);

    $this->actingInCompany($this->adminA, $this->companyA)->get(route('dashboard'))->assertForbidden();
});

test('inactive companies cannot be entered', function () {
    $this->companyA->forceFill(['is_active' => false])->save();

    $this->actingInCompany($this->adminA, $this->companyA)->get(route('dashboard'))->assertForbidden();
});

test('super admin can enter any active company', function () {
    $root = User::factory()->superAdmin()->create();

    $this->actingInCompany($root, $this->companyB)->get(route('masters.index', 'vendors'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('company.current.id', $this->companyB->id)->has('records.data', 1));
});

test('company admins cannot reach platform company management', function () {
    $this->actingInCompany($this->adminA, $this->companyA)->get(route('platform.companies.index'))->assertForbidden();
});

test('companies are provisioned with their own roles, masters and workflows', function () {
    foreach ([$this->companyA, $this->companyB] as $company) {
        $this->inCompany($company, function () use ($company) {
            expect(Role::query()->where('team_id', $company->id)->count())->toBe(count(DefaultRoles::definitions()))
                ->and(Unit::query()->count())->toBeGreaterThan(10)
                ->and(TaxRate::query()->where('name', 'GST 18%')->value('cgst_rate'))->toBe('9.0000')
                ->and(ApprovalWorkflow::query()->where('document_type', 'purchase_order')->exists())->toBeTrue()
                ->and(FinancialYear::query()->where('is_current', true)->count())->toBe(1);
        });
    }
});

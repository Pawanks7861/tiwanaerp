<?php

use App\Models\Core\CompanyUser;
use App\Models\Core\Role;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.multi_company' => false]);

    $this->companyA = $this->createCompany(['name' => 'Alpha Builders']);
    $this->companyB = $this->createCompany(['name' => 'Beta Infra']);
    $this->adminA = $this->createMember($this->companyA, DefaultRoles::COMPANY_ADMIN);
    $this->colleague = $this->createMember($this->companyA, DefaultRoles::SITE_ENGINEER, ['name' => 'Alpha Colleague']);
    $this->adminB = $this->createMember($this->companyB, DefaultRoles::COMPANY_ADMIN, ['name' => 'Beta Admin']);
    $this->vendorB = $this->inCompany($this->companyB, fn () => Vendor::query()->create([
        'code' => 'VEN-B9',
        'name' => 'Beta Cement',
        'is_active' => true,
    ]));
    $this->projectB = $this->inCompany($this->companyB, fn () => Project::factory()->create(['name' => 'Beta Tower']));
});

test('the company switcher data and platform flag are absent for a normal user', function () {
    $this->actingInCompany($this->adminA, $this->companyA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.multi_company', false)
            ->where('company.available', [])
            ->where('company.current.id', $this->companyA->id)
            ->where('company.current.name', 'Alpha Builders'));
});

test('a direct company switch is refused while multi-company is disabled', function () {
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('company.switch'), ['company_id' => $this->companyB->id])
        ->assertNotFound();

    expect($this->adminA->fresh()->current_company_id)->toBe($this->companyA->id);
});

test('platform company pages are unavailable while multi-company is disabled', function () {
    $root = User::factory()->superAdmin()->create(['current_company_id' => $this->companyA->id]);

    $this->actingInCompany($this->adminA, $this->companyA)->get(route('platform.companies.index'))->assertNotFound();
    $this->actingInCompany($this->adminA, $this->companyA)->get(route('platform.companies.create'))->assertNotFound();
    $this->actingInCompany($root, $this->companyA)->get(route('platform.companies.index'))->assertNotFound();
    $this->actingInCompany($root, $this->companyA)->post(route('platform.companies.store'), [])->assertNotFound();
});

test('company settings and user management stay available for the active company', function () {
    $role = Role::query()->where('team_id', $this->companyA->id)->where('name', DefaultRoles::PROJECT_MANAGER)->sole();

    $this->actingInCompany($this->adminA, $this->companyA)->get(route('admin.company.edit'))->assertOk();
    $this->actingInCompany($this->adminA, $this->companyA)->get(route('admin.users.index'))->assertOk();

    $this->actingInCompany($this->adminA, $this->companyA)->post(route('admin.users.store'), [
        'name' => 'New Member',
        'email' => 'new.member@example.test',
        'password' => 'Site1234',
        'password_confirmation' => 'Site1234',
        'roles' => [$role->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'new.member@example.test')->sole();
    expect(CompanyUser::query()->where('user_id', $user->id)->where('company_id', $this->companyA->id)->value('is_active'))->toBeTruthy()
        ->and($user->current_company_id)->toBe($this->companyA->id);
});

test('a stored active company is kept and a missing one is chosen and saved', function () {
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->adminA, $this->companyB)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('company.current.id', $this->companyA->id));

    $this->adminA->forceFill(['current_company_id' => null])->save();

    $this->actingAs($this->adminA->fresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('company.current.id', $this->companyA->id));

    expect($this->adminA->fresh()->current_company_id)->toBe($this->companyA->id);
});

test('another company stays unreachable in records, chat and the dashboard', function () {
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->adminA, $this->companyA)
        ->get(route('masters.show', ['vendors', $this->vendorB->id]))
        ->assertNotFound();

    $directory = $this->actingInCompany($this->adminA, $this->companyA)
        ->getJson(route('chat.directory'))
        ->assertOk()
        ->json('users');

    $ids = collect($directory)->pluck('id');
    expect($ids)->toContain($this->colleague->id)->not->toContain($this->adminB->id);

    $dashboard = $this->actingInCompany($this->adminA, $this->companyA)->get(route('dashboard'))->assertOk();
    $dashboard->assertInertia(fn (Assert $page) => $page
        ->where('company.current.id', $this->companyA->id)
        ->where('projectSwitcher', function ($rows) {
            return collect($rows)->pluck('id')->doesntContain($this->projectB->id);
        }));
});

test('an api company header cannot switch companies while multi-company is disabled', function () {
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);

    $token = $this->postJson(route('api.v1.auth.login'), [
        'email' => $this->adminA->email,
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertOk()->assertJsonCount(1, 'companies')->assertJsonPath('companies.0.id', $this->companyA->id)->json('token');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Company-Id' => (string) $this->companyB->id,
    ])->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('company.id', $this->companyA->id);
});

test('turning multi-company back on restores switching and platform company pages', function () {
    config(['features.multi_company' => true]);
    app(CompanyService::class)->addMember($this->companyB, $this->adminA, [DefaultRoles::SITE_ENGINEER]);
    $root = User::factory()->superAdmin()->create(['current_company_id' => $this->companyA->id]);

    $this->actingInCompany($this->adminA, $this->companyA)
        ->post(route('company.switch'), ['company_id' => $this->companyB->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('current_company_id', $this->companyB->id);

    $this->actingInCompany($root, $this->companyA)->get(route('platform.companies.index'))->assertOk();

    $this->actingInCompany($this->adminA, $this->companyA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.multi_company', true)
            ->has('company.available', 2));
});

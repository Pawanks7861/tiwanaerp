<?php

use App\Models\Core\CompanyUser;
use App\Models\Core\Role;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = $this->createCompany();
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
});

function companyRole($test, string $name): Role
{
    return Role::query()->where('team_id', $test->company->id)->where('name', $name)->sole();
}

/**
 * @param  list<string>  $permissions
 */
function customRole($test, string $name, array $permissions): Role
{
    $role = Role::query()->create(['team_id' => $test->company->id, 'name' => $name, 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissionNames($permissions);

    return $role;
}

function rolesOf(User $user, int $companyId): array
{
    setPermissionsTeamId($companyId);
    $names = $user->fresh()->roles()->pluck('name')->all();
    setPermissionsTeamId(null);

    return $names;
}

test('an admin creates a user with company roles', function () {
    $pmRole = companyRole($this, DefaultRoles::PROJECT_MANAGER);

    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'Gurpreet Singh', 'email' => 'Gurpreet@Example.test', 'mobile' => '9876543210',
        'password' => 'Site1234', 'password_confirmation' => 'Site1234', 'roles' => [$pmRole->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'gurpreet@example.test')->sole();
    expect($user->is_active)->toBeTrue()
        ->and(rolesOf($user, $this->company->id))->toBe([DefaultRoles::PROJECT_MANAGER])
        ->and(CompanyUser::query()->where('user_id', $user->id)->where('company_id', $this->company->id)->value('is_active'))->toBeTruthy();

    $this->actingInCompany($this->admin, $this->company)->get(route('admin.users.index', ['search' => 'gurpreet']))
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Index', false)->has('users.data', 1));
});

test('a new user needs a password; an existing account is attached without changing its profile', function () {
    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'No Password', 'email' => 'nopass@example.test', 'roles' => [],
    ])->assertSessionHasErrors('password');

    $other = $this->createCompany();
    $existing = $this->createMember($other, DefaultRoles::SITE_ENGINEER, ['name' => 'Original Name', 'email' => 'shared@example.test']);

    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'Renamed', 'email' => 'shared@example.test', 'password' => 'Hijack123', 'password_confirmation' => 'Hijack123', 'roles' => [],
    ])->assertSessionHasNoErrors();

    $existing->refresh();
    expect($existing->name)->toBe('Original Name')
        ->and(Hash::check('password', $existing->password))->toBeTrue()
        ->and($existing->accessibleCompaniesQuery()->pluck('companies.id')->sort()->values()->all())
        ->toBe(collect([$this->company->id, $other->id])->sort()->values()->all());

    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'Again', 'email' => 'shared@example.test', 'roles' => [],
    ])->assertSessionHasErrors('email');
});

test('a company admin cannot change the profile of a user who also belongs to another company', function () {
    $other = $this->createCompany();
    $shared = $this->createMember($other, [], ['name' => 'Shared User']);
    app(CompanyService::class)->addMember($this->company, $shared, [DefaultRoles::SITE_ENGINEER]);

    $this->actingInCompany($this->admin, $this->company)->put(route('admin.users.update', $shared), [
        'name' => 'Changed', 'email' => $shared->email, 'roles' => [companyRole($this, DefaultRoles::STORE_MANAGER)->id],
    ])->assertSessionHasNoErrors();

    expect($shared->fresh()->name)->toBe('Shared User')
        ->and(rolesOf($shared, $this->company->id))->toBe([DefaultRoles::STORE_MANAGER])
        ->and(rolesOf($shared, $other->id))->toBe([]);
});

test('roles of another company cannot be assigned', function () {
    $other = $this->createCompany();
    $foreignRole = Role::query()->where('team_id', $other->id)->where('name', DefaultRoles::COMPANY_ADMIN)->sole();

    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'Pass1234', 'password_confirmation' => 'Pass1234',
        'roles' => [$foreignRole->id],
    ])->assertSessionHasErrors('roles');

    expect(User::query()->where('email', 'sneaky@example.test')->exists())->toBeFalse();
});

test('user managers cannot grant roles carrying permissions they do not hold', function () {
    customRole($this, 'HR', ['dashboard.view', 'admin.users.view', 'admin.users.manage']);
    $viewer = customRole($this, 'Dashboard Viewer', ['dashboard.view']);
    $hr = $this->createMember($this->company, 'HR');

    $payload = ['name' => 'New Joinee', 'email' => 'joinee@example.test', 'password' => 'Pass1234', 'password_confirmation' => 'Pass1234'];

    $this->actingInCompany($hr, $this->company)->post(route('admin.users.store'), $payload + [
        'roles' => [companyRole($this, DefaultRoles::COMPANY_ADMIN)->id],
    ])->assertSessionHasErrors('roles');

    $this->actingInCompany($hr, $this->company)->post(route('admin.users.store'), $payload + [
        'roles' => [$viewer->id],
    ])->assertSessionHasNoErrors();
});

test('role managers cannot grant permissions they do not hold', function () {
    customRole($this, 'Role Editor', ['dashboard.view', 'admin.roles.view', 'admin.roles.manage']);
    $editor = $this->createMember($this->company, 'Role Editor');

    $this->actingInCompany($editor, $this->company)->post(route('admin.roles.store'), [
        'name' => 'Escalated', 'permissions' => ['dashboard.view', 'payments.approve'],
    ])->assertSessionHasErrors('permissions');

    $this->actingInCompany($editor, $this->company)->post(route('admin.roles.store'), [
        'name' => 'Limited', 'permissions' => ['dashboard.view'],
    ])->assertSessionHasNoErrors();
});

test('the last active company admin cannot be demoted or deactivated', function () {
    $as = $this->actingInCompany($this->admin, $this->company);

    $as->patch(route('admin.users.status', $this->admin), ['is_active' => false])->assertForbidden();
    $as->put(route('admin.users.update', $this->admin), [
        'name' => $this->admin->name, 'email' => $this->admin->email, 'roles' => [companyRole($this, DefaultRoles::DIRECTOR)->id],
    ])->assertSessionHasErrors('roles');

    $second = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($second, $this->company)->patch(route('admin.users.status', $this->admin), ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect(CompanyUser::query()->where('user_id', $this->admin->id)->value('is_active'))->toBeFalsy();
});

test('deactivated members lose access to the company', function () {
    $engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);

    $this->actingInCompany($this->admin, $this->company)->patch(route('admin.users.status', $engineer), ['is_active' => false])
        ->assertSessionHasNoErrors();

    $this->actingInCompany($engineer->fresh(), $this->company)->get(route('dashboard'))->assertForbidden();
});

test('users outside the company cannot be viewed or edited', function () {
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::SITE_ENGINEER);

    $this->actingInCompany($this->admin, $this->company)->get(route('admin.users.edit', $outsider))->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->patch(route('admin.users.status', $outsider), ['is_active' => false])->assertForbidden();
});

test('custom roles can be created, edited and deleted while system roles are protected', function () {
    $as = $this->actingInCompany($this->admin, $this->company);

    $as->post(route('admin.roles.store'), ['name' => 'Site Accountant', 'permissions' => ['expenses.view', 'expenses.create']])
        ->assertSessionHasNoErrors();
    $role = companyRole($this, 'Site Accountant');
    expect($role->permissions()->pluck('name')->sort()->values()->all())->toBe(['expenses.create', 'expenses.view']);

    $as->put(route('admin.roles.update', $role), ['name' => 'Site Accounts', 'permissions' => ['expenses.view']])->assertSessionHasNoErrors();
    expect($role->fresh()->name)->toBe('Site Accounts')->and($role->permissions()->pluck('name')->all())->toBe(['expenses.view']);

    $as->post(route('admin.roles.store'), ['name' => 'Bogus', 'permissions' => ['does.not.exist']])->assertSessionHasErrors('permissions.0');

    $adminRole = companyRole($this, DefaultRoles::COMPANY_ADMIN);
    $as->put(route('admin.roles.update', $adminRole), ['name' => DefaultRoles::COMPANY_ADMIN, 'permissions' => []])->assertForbidden();
    $as->delete(route('admin.roles.destroy', companyRole($this, DefaultRoles::DIRECTOR)))->assertForbidden();
    $as->put(route('admin.roles.update', companyRole($this, DefaultRoles::DIRECTOR)), ['name' => 'Boss', 'permissions' => []])
        ->assertSessionHasErrors('name');

    $this->createMember($this->company, 'Site Accounts');
    $as->delete(route('admin.roles.destroy', $role))->assertSessionHasErrors('role');
});

test('roles of another company are not found', function () {
    $other = $this->createCompany();
    $foreign = Role::query()->where('team_id', $other->id)->where('name', DefaultRoles::DIRECTOR)->sole();

    $this->actingInCompany($this->admin, $this->company)->get(route('admin.roles.edit', $foreign))->assertNotFound();
    $this->actingInCompany($this->admin, $this->company)->delete(route('admin.roles.destroy', $foreign))->assertNotFound();
});

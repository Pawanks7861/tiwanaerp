<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\Core\CompanyUser;
use App\Models\Core\Role;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Company user administration. Users are global; this service manages them through the
 * current company's membership and company-scoped roles.
 */
class UserService
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly CompanyService $companies,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Create a new user, or add an existing user (matched by email) to the current company.
     * Existing users' profile and password are never changed from another company.
     *
     * @param  array{name: string, email: string, mobile?: ?string, password?: ?string, roles: list<int>, permissions?: list<string>, menu_items?: list<string>}  $data
     * @return array{user: User, existing: bool}
     */
    public function addToCompany(array $data, User $actor): array
    {
        $company = $this->tenancy->require();
        $roles = $this->assignableRoles($data['roles'], $actor);
        $permissions = $data['permissions'] ?? [];
        $menuItems = $this->normalizeMenuItems($data['menu_items'] ?? []);

        return DB::transaction(function () use ($data, $company, $roles, $permissions, $menuItems) {
            $user = User::query()->where('email', $data['email'])->first();
            $existing = $user !== null;

            if ($existing && $user->memberships()->where('company_id', $company->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This user is already a member of this company.']);
            }

            if (! $existing) {
                if (blank($data['password'] ?? null)) {
                    throw ValidationException::withMessages(['password' => 'A password is required for a new user.']);
                }

                $user = new User;
                $user->fill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'mobile' => $data['mobile'] ?? null,
                    'password' => $data['password'],
                ]);
                $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
            }

            $membership = $this->companies->addMember($company, $user, $roles->pluck('name')->all());
            $this->syncDirectPermissions($company, $user, $permissions);
            $this->syncMenuItems($membership, $menuItems);

            return ['user' => $user, 'existing' => $existing];
        });
    }

    /**
     * @param  array{name?: string, email?: string, mobile?: ?string, password?: ?string, roles: list<int>, permissions?: list<string>, menu_items?: list<string>}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $company = $this->tenancy->require();
        $roles = $this->assignableRoles($data['roles'], $actor);
        $permissions = $data['permissions'] ?? [];
        $menuItems = $this->normalizeMenuItems($data['menu_items'] ?? []);

        return DB::transaction(function () use ($user, $data, $company, $roles, $permissions, $menuItems, $actor) {
            if ($this->canEditProfile($user, $actor)) {
                $user->fill(array_filter([
                    'name' => $data['name'] ?? null,
                    'email' => $data['email'] ?? null,
                    'password' => $data['password'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if (array_key_exists('mobile', $data)) {
                    $user->mobile = $data['mobile'];
                }
                $user->save();
            }

            $roleNames = $roles->pluck('name')->all();
            if (! in_array(DefaultRoles::COMPANY_ADMIN, $roleNames, true)) {
                $this->guardLastAdmin($user);
            }

            $this->companies->syncRoles($company, $user, $roleNames);
            $this->syncDirectPermissions($company, $user, $permissions);

            $membership = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
            $this->syncMenuItems($membership, $menuItems);

            return $user;
        });
    }

    public function setActive(User $user, bool $active): void
    {
        $company = $this->tenancy->require();

        if (! $active) {
            $this->guardLastAdmin($user);
        }

        $membership = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $membership->is_active = $active;
        $membership->save();

        if (! $active && ! $user->memberships()->where('is_active', true)->exists()) {
            $user->tokens()->delete();
        }
    }

    /**
     * Profile fields (name, email, password) are global. They may only be edited by a company admin
     * when the user belongs to no other company, so one company cannot take over another's account.
     */
    public function canEditProfile(User $user, User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return ! $user->memberships()->where('company_id', '!=', $this->tenancy->require()->id)->exists();
    }

    /**
     * Roles must belong to the current company, and a non-super-admin cannot grant a role
     * carrying permissions they do not hold themselves.
     *
     * @param  list<int>  $roleIds
     * @return Collection<int, Role>
     */
    public function assignableRoles(array $roleIds, User $actor): Collection
    {
        $roles = Role::query()->forCurrentCompany()->whereKey($roleIds)->with('permissions:id,name')->get();

        if ($roles->count() !== count(array_unique($roleIds))) {
            throw ValidationException::withMessages(['roles' => 'One or more selected roles are invalid.']);
        }

        if (! $actor->isSuperAdmin()) {
            $own = $actor->getAllPermissions()->pluck('name');
            foreach ($roles as $role) {
                if ($role->permissions->pluck('name')->diff($own)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'roles' => "You cannot assign the {$role->name} role because it has permissions you do not hold.",
                    ]);
                }
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function syncDirectPermissions(Company $company, User $user, array $permissionNames): void
    {
        $this->tenancy->runAs($company, function () use ($company, $user, $permissionNames) {
            $previousTeam = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($company->id);

            try {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncPermissions($permissionNames);
            } finally {
                $this->registrar->setPermissionsTeamId($previousTeam);
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }

    /**
     * @param  list<string>|null  $menuItems
     */
    public function syncMenuItems(CompanyUser $membership, ?array $menuItems): void
    {
        $membership->menu_items = $menuItems;
        $membership->save();
    }

    /**
     * Empty selection means no override (show every permission-allowed item).
     *
     * @param  list<string>  $menuItems
     * @return list<string>|null
     */
    private function normalizeMenuItems(array $menuItems): ?array
    {
        $items = array_values(array_unique(array_filter($menuItems, fn ($v) => is_string($v) && $v !== '')));

        return $items === [] ? null : $items;
    }

    private function guardLastAdmin(User $user): void
    {
        $companyId = $this->tenancy->require()->id;
        $adminRole = Role::query()->forCurrentCompany()->where('name', DefaultRoles::COMPANY_ADMIN)->first();
        if ($adminRole === null) {
            return;
        }

        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from(config('permission.table_names.model_has_roles'))
                ->whereColumn('model_id', 'users.id')
                ->where('model_type', (new User)->getMorphClass())
                ->where('role_id', $adminRole->id)
                ->where(config('permission.column_names.team_foreign_key'), $companyId))
            ->pluck('id');

        if ($admins->count() === 1 && $admins->first() === $user->id) {
            throw ValidationException::withMessages([
                'roles' => 'This is the only active Company Admin. Assign another Company Admin first.',
            ]);
        }
    }
}

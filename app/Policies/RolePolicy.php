<?php

namespace App\Policies;

use App\Models\Core\Role;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->ownsRole($role) && $user->can('admin.roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.roles.manage');
    }

    /**
     * The Company Admin role always keeps every permission so a company can never lock itself out.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->ownsRole($role)
            && $user->can('admin.roles.manage')
            && ! ($role->is_system && $role->name === DefaultRoles::COMPANY_ADMIN);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->ownsRole($role) && $user->can('admin.roles.manage') && ! $role->is_system;
    }

    private function ownsRole(Role $role): bool
    {
        return (int) $role->team_id === app(CurrentCompany::class)->id();
    }
}

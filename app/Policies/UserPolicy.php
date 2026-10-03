<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

/**
 * Users are global, but company admins only see and manage members of their current company.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('admin.users.view') && $this->isMember($target);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.users.manage');
    }

    public function update(User $user, User $target): bool
    {
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('admin.users.manage') && $this->isMember($target);
    }

    /**
     * Deactivating yourself would lock you out; it must be done by another admin.
     */
    public function toggleActive(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->update($user, $target);
    }

    private function isMember(User $target): bool
    {
        $companyId = app(CurrentCompany::class)->id();

        return $companyId !== null && $target->memberships()->where('company_id', $companyId)->exists();
    }
}

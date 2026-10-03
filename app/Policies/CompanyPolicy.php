<?php

namespace App\Policies;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

/**
 * Creating and listing companies is platform-level (super admin, granted by Gate::before).
 * Company admins may only manage the settings of their current company.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Company $company): bool
    {
        return false;
    }

    public function viewSettings(User $user, Company $company): bool
    {
        return $company->id === app(CurrentCompany::class)->id() && $user->can('admin.settings.view');
    }

    public function manageSettings(User $user, Company $company): bool
    {
        return $company->id === app(CurrentCompany::class)->id() && $user->can('admin.settings.manage');
    }
}

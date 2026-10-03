<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\Core\CompanyUser;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class CompanyService
{
    public function __construct(
        private readonly CompanyProvisioner $provisioner,
        private readonly CurrentCompany $tenancy,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $admin = null): Company
    {
        return DB::transaction(function () use ($data, $admin) {
            $company = Company::query()->create($data);
            $this->provisioner->provision($company);

            if ($admin) {
                $this->addMember($company, $admin, [DefaultRoles::COMPANY_ADMIN]);
            }

            return $company;
        });
    }

    /**
     * Add (or re-activate) a membership and set the user's roles in that company.
     *
     * @param  list<string|int>  $roles  role names or IDs belonging to the company
     */
    public function addMember(Company $company, User $user, array $roles = []): CompanyUser
    {
        $membership = CompanyUser::query()->firstOrNew(['company_id' => $company->id, 'user_id' => $user->id]);
        $membership->is_active = true;
        $membership->save();

        $this->syncRoles($company, $user, $roles);

        if ($user->current_company_id === null) {
            $user->forceFill(['current_company_id' => $company->id])->saveQuietly();
        }

        return $membership;
    }

    /**
     * @param  list<string|int>  $roles
     */
    public function syncRoles(Company $company, User $user, array $roles): void
    {
        $this->tenancy->runAs($company, function () use ($company, $user, $roles) {
            $previousTeam = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($company->id);

            try {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles($roles);
            } finally {
                $this->registrar->setPermissionsTeamId($previousTeam);
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}

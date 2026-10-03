<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Services\Core\CompanyProvisioner;
use Illuminate\Database\Seeder;

/**
 * Syncs the permission catalogue and re-applies default roles to every company. Safe to re-run
 * after adding permissions in PermissionCatalog.
 */
class PermissionSeeder extends Seeder
{
    public function run(CompanyProvisioner $provisioner): void
    {
        $provisioner->ensurePermissions();

        Company::query()->each(fn (Company $company) => $provisioner->provision($company));
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Core\CompanyProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('permissions:refresh-system-defaults')]
#[Description('Grant missing DefaultRoles permissions to existing system roles (additive; never strips custom extras)')]
class RefreshSystemRolePermissions extends Command
{
    public function handle(CompanyProvisioner $provisioner): int
    {
        $result = $provisioner->refreshAllSystemRoleDefaults();

        $this->info(sprintf(
            'Refreshed system role defaults across %d companies (%d roles touched, %d permissions granted).',
            $result['companies'],
            $result['roles_touched'],
            $result['permissions_granted'],
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Support\Permissions;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission check in a given company, also outside an HTTP request (approval hooks, queue,
 * console), where the permission team id may not be the document's company.
 */
final class CompanyPermission
{
    public static function check(?User $user, int $companyId, string $permission): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->checkPermissionTo($permission);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}

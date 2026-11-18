<?php

namespace App\Support\Projects;

use App\Enums\ProjectRole;
use App\Models\Core\Role;
use App\Support\Permissions\DefaultRoles;

/**
 * Bridges company Roles & Permissions (Spatie) to project_users.project_role used by approvals.
 */
final class ProjectCompanyRoleMapper
{
    public static function toProjectRole(Role|string $companyRole): ProjectRole
    {
        $name = $companyRole instanceof Role ? $companyRole->name : $companyRole;

        return match ($name) {
            DefaultRoles::PROJECT_MANAGER => ProjectRole::Manager,
            DefaultRoles::SITE_ENGINEER,
            DefaultRoles::BOQ_PREP,
            DefaultRoles::CIVIL_LEAD => ProjectRole::Engineer,
            DefaultRoles::PURCHASE_MANAGER => ProjectRole::Purchase,
            DefaultRoles::STORE_MANAGER,
            DefaultRoles::STORE_INCHARGE => ProjectRole::Store,
            DefaultRoles::BILLING_ENGINEER => ProjectRole::Billing,
            DefaultRoles::QUALITY_ENGINEER => ProjectRole::Quality,
            DefaultRoles::ACCOUNTANT => ProjectRole::Accounts,
            DefaultRoles::COMPANY_ADMIN,
            DefaultRoles::DIRECTOR,
            DefaultRoles::MANAGEMENT => ProjectRole::Viewer,
            default => ProjectRole::Viewer,
        };
    }

    /**
     * Preferred company role name when only a ProjectRole is known (e.g. auto-adding the PM).
     */
    public static function preferredCompanyRoleName(ProjectRole $role): string
    {
        return match ($role) {
            ProjectRole::Manager => DefaultRoles::PROJECT_MANAGER,
            ProjectRole::Engineer => DefaultRoles::SITE_ENGINEER,
            ProjectRole::Purchase => DefaultRoles::PURCHASE_MANAGER,
            ProjectRole::Store => DefaultRoles::STORE_MANAGER,
            ProjectRole::Billing => DefaultRoles::BILLING_ENGINEER,
            ProjectRole::Quality => DefaultRoles::QUALITY_ENGINEER,
            ProjectRole::Accounts => DefaultRoles::ACCOUNTANT,
            ProjectRole::Viewer => DefaultRoles::SITE_ENGINEER,
        };
    }
}

<?php

use App\Models\Core\Company;
use App\Services\Core\CompanyProvisioner;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BOQ commercial approval: Director → Civil Lead (company-wide workflows).
 * Also grants Civil Lead the missing boq.approve / boq.view_costs defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $directorId = DB::table('roles')
                ->where('team_id', $companyId)
                ->where('name', DefaultRoles::DIRECTOR)
                ->where('guard_name', 'web')
                ->value('id');
            $civilLeadId = DB::table('roles')
                ->where('team_id', $companyId)
                ->where('name', DefaultRoles::CIVIL_LEAD)
                ->where('guard_name', 'web')
                ->value('id');

            if ($directorId === null || $civilLeadId === null) {
                continue;
            }

            $workflowIds = DB::table('approval_workflows')
                ->where('company_id', $companyId)
                ->where('document_type', 'boq')
                ->whereNull('project_id')
                ->pluck('id');

            if ($workflowIds->isEmpty()) {
                continue;
            }

            DB::table('approval_steps')
                ->whereIn('approval_workflow_id', $workflowIds)
                ->where('approver_type', 'role')
                ->where('role_id', $directorId)
                ->update([
                    'role_id' => $civilLeadId,
                    'name' => 'Commercial Approval',
                    'updated_at' => $now,
                ]);
        }

        $provisioner = app(CompanyProvisioner::class);
        foreach (Company::query()->cursor() as $company) {
            $provisioner->grantMissingSystemRoleDefaults($company);
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $directorId = DB::table('roles')
                ->where('team_id', $companyId)
                ->where('name', DefaultRoles::DIRECTOR)
                ->where('guard_name', 'web')
                ->value('id');
            $civilLeadId = DB::table('roles')
                ->where('team_id', $companyId)
                ->where('name', DefaultRoles::CIVIL_LEAD)
                ->where('guard_name', 'web')
                ->value('id');

            if ($directorId === null || $civilLeadId === null) {
                continue;
            }

            $workflowIds = DB::table('approval_workflows')
                ->where('company_id', $companyId)
                ->where('document_type', 'boq')
                ->whereNull('project_id')
                ->pluck('id');

            if ($workflowIds->isEmpty()) {
                continue;
            }

            DB::table('approval_steps')
                ->whereIn('approval_workflow_id', $workflowIds)
                ->where('approver_type', 'role')
                ->where('role_id', $civilLeadId)
                ->where('name', 'Commercial Approval')
                ->update([
                    'role_id' => $directorId,
                    'updated_at' => $now,
                ]);
        }
    }
};

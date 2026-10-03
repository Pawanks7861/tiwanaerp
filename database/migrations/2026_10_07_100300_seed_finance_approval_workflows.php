<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companies provisioned before Phase 7 get the finance workflows that CompanyProvisioner now seeds
 * for new companies: vendor bill and retention release (Project Manager → Director). Expense and
 * client bill workflows were seeded from Phase 1; any missing one is added too. Existing workflows
 * are left alone.
 */
return new class extends Migration
{
    private const WORKFLOWS = [
        'expense' => ['Expense approval', 'Accountant'],
        'client_invoice' => ['Client Bill approval', 'Director'],
        'vendor_bill' => ['Vendor Bill approval', 'Director'],
        'retention_release' => ['Retention Release approval', 'Director'],
    ];

    private const ADDED = ['vendor_bill', 'retention_release'];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::WORKFLOWS as $type => [$name, $roleName]) {
                $exists = DB::table('approval_workflows')
                    ->where('company_id', $companyId)
                    ->where('document_type', $type)
                    ->whereNull('project_id')
                    ->exists();
                $roleId = DB::table('roles')->where('team_id', $companyId)->where('name', $roleName)->value('id');

                if ($exists || $roleId === null) {
                    continue;
                }

                $workflowId = DB::table('approval_workflows')->insertGetId([
                    'company_id' => $companyId,
                    'document_type' => $type,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('approval_steps')->insert([
                    [
                        'approval_workflow_id' => $workflowId, 'level' => 1, 'name' => 'Project Manager',
                        'approver_type' => 'project_role', 'project_role' => 'manager', 'role_id' => null,
                        'mode' => 'any', 'created_at' => $now, 'updated_at' => $now,
                    ],
                    [
                        'approval_workflow_id' => $workflowId, 'level' => 2, 'name' => $roleName,
                        'approver_type' => 'role', 'project_role' => null, 'role_id' => $roleId,
                        'mode' => 'any', 'created_at' => $now, 'updated_at' => $now,
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Their approval history goes with the tables dropped by the finance migration.
        $workflowIds = DB::table('approval_workflows')
            ->whereIn('document_type', self::ADDED)
            ->whereNotIn('id', DB::table('approval_requests')->select('approval_workflow_id'))
            ->pluck('id');

        DB::table('approval_steps')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();
    }
};

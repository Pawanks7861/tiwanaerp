<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companies provisioned before Phase 4 get the same default inventory workflows that
 * CompanyProvisioner now seeds for new companies (single Project Manager step). Companies
 * that already configured a workflow for these document types are left alone.
 */
return new class extends Migration
{
    private const TYPES = [
        'material_issue' => 'Material Issue approval',
        'material_return' => 'Material Return approval',
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::TYPES as $type => $name) {
                $exists = DB::table('approval_workflows')
                    ->where('company_id', $companyId)
                    ->where('document_type', $type)
                    ->whereNull('project_id')
                    ->exists();

                if ($exists) {
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
                    'approval_workflow_id' => $workflowId,
                    'level' => 1,
                    'name' => 'Project Manager',
                    'approver_type' => 'project_role',
                    'project_role' => 'manager',
                    'mode' => 'any',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $types = array_keys(self::TYPES);

        // Approval history of the inventory documents goes with the tables dropped by the earlier
        // Phase 4 migrations; workflows still referenced elsewhere are kept.
        $requestIds = DB::table('approval_requests')->whereIn('approvable_type', $types)->pluck('id');
        DB::table('approval_actions')->whereIn('approval_request_id', $requestIds)->delete();
        DB::table('approval_requests')->whereIn('id', $requestIds)->delete();

        $workflowIds = DB::table('approval_workflows')
            ->whereIn('document_type', $types)
            ->whereNotIn('id', DB::table('approval_requests')->select('approval_workflow_id'))
            ->pluck('id');

        DB::table('approval_steps')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();
    }
};

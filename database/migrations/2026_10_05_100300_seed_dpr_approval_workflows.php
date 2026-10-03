<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companies provisioned before Phase 5 get the default DPR workflow that CompanyProvisioner now
 * seeds for new companies (single Project Manager step). Existing DPR workflows are left alone.
 */
return new class extends Migration
{
    private const TYPE = 'dpr';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('approval_workflows')
                ->where('company_id', $companyId)
                ->where('document_type', self::TYPE)
                ->whereNull('project_id')
                ->exists();

            if ($exists) {
                continue;
            }

            $workflowId = DB::table('approval_workflows')->insertGetId([
                'company_id' => $companyId,
                'document_type' => self::TYPE,
                'name' => 'DPR approval',
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

    public function down(): void
    {
        // DPR approval history goes with the dprs table dropped by the earlier Phase 5 migration.
        $requestIds = DB::table('approval_requests')->where('approvable_type', self::TYPE)->pluck('id');
        DB::table('approval_actions')->whereIn('approval_request_id', $requestIds)->delete();
        DB::table('approval_requests')->whereIn('id', $requestIds)->delete();

        $workflowIds = DB::table('approval_workflows')
            ->where('document_type', self::TYPE)
            ->whereNotIn('id', DB::table('approval_requests')->select('approval_workflow_id'))
            ->pluck('id');

        DB::table('approval_steps')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();
    }
};

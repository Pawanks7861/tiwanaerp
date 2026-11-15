<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relabel company-wide BOQ workflow steps to Technical / Commercial Approval.
 * Only renames default-ish names so custom admin labels are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workflowIds = DB::table('approval_workflows')
            ->where('document_type', 'boq')
            ->whereNull('project_id')
            ->pluck('id');

        foreach ($workflowIds as $workflowId) {
            $steps = DB::table('approval_steps')
                ->where('approval_workflow_id', $workflowId)
                ->orderBy('level')
                ->get(['id', 'level', 'name']);

            if ($steps->isEmpty()) {
                continue;
            }

            $min = (int) $steps->min('level');
            $max = (int) $steps->max('level');

            foreach ($steps as $step) {
                $name = (string) $step->name;
                $level = (int) $step->level;
                $defaultish = in_array($name, ['Project Manager', 'Director', 'Management', 'Technical Approval', 'Commercial Approval'], true)
                    || str_contains(strtolower($name), 'project manager')
                    || str_contains(strtolower($name), 'director');

                if (! $defaultish) {
                    continue;
                }

                $newName = $level === $min
                    ? 'Technical Approval'
                    : ($level === $max ? 'Commercial Approval' : $name);

                if ($newName !== $name) {
                    DB::table('approval_steps')->where('id', $step->id)->update([
                        'name' => $newName,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Irreversible label rename — leave as-is.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deleting a planning activity previously left BOQ maps is_active=1, so Available Qty
 * stayed consumed while the UI showed no link. Release those orphan maps on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('boq_planning_maps')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('project_tasks')
                        ->whereColumn('project_tasks.id', 'boq_planning_maps.project_task_id')
                        ->whereNull('project_tasks.deleted_at');
                });
            })
            ->update([
                'is_active' => false,
                'is_primary' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible data cleanup — cannot safely restore which maps were orphaned.
    }
};

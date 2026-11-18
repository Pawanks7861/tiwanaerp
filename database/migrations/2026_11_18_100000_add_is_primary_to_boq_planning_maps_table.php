<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One primary map per Planning Activity drives planned_qty / progress basis
 * when multiple BOQ items are linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_planning_maps', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('is_active');
            $table->index(['project_task_id', 'is_primary', 'is_active'], 'bpm_task_primary_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('boq_planning_maps', function (Blueprint $table) {
            $table->dropIndex('bpm_task_primary_active_idx');
            $table->dropColumn('is_primary');
        });
    }
};

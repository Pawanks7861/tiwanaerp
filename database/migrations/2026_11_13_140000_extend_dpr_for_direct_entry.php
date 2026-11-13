<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct DPR site execution (Site Diary retired from workflow).
 * Extends existing DPR tables — does not drop site_diary_* tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dprs', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('project_id')->constrained('sites')->nullOnDelete();
            $table->string('source', 40)->default('direct')->after('remarks'); // direct | legacy_site_diary
            $table->text('work_performed')->nullable()->after('source');
            $table->text('constraints_summary')->nullable()->after('work_performed');
            $table->string('department', 120)->nullable()->after('constraints_summary');
            $table->string('agency_name', 160)->nullable()->after('department');
        });

        Schema::table('dpr_items', function (Blueprint $table) {
            $table->string('work_area', 160)->nullable()->after('description');
            $table->string('activity_name_snapshot', 500)->nullable()->after('work_area');
            $table->string('unit_snapshot', 40)->nullable()->after('activity_name_snapshot');
            $table->date('planned_start_date')->nullable()->after('unit_id');
            $table->date('planned_end_date')->nullable()->after('planned_start_date');
            Columns::quantity($table, 'previous_executed_qty')->nullable()->after('planned_qty');
            Columns::quantity($table, 'today_target_qty')->nullable()->after('previous_executed_qty');
            Columns::quantity($table, 'hold_quantity')->default(0)->after('executed_qty');
            $table->string('hold_reason', 80)->nullable()->after('hold_quantity');
            $table->date('hold_since')->nullable()->after('hold_reason');
            $table->string('hold_responsible', 120)->nullable()->after('hold_since');
            $table->date('hold_expected_resolution')->nullable()->after('hold_responsible');
            $table->string('hold_status', 40)->nullable()->after('hold_expected_resolution');
            Columns::quantity($table, 'target_variance')->nullable()->after('balance_qty');
            $table->decimal('achievement_percent', 8, 4)->nullable()->after('target_variance');
            $table->decimal('completion_percent', 8, 4)->nullable()->after('achievement_percent');
            $table->string('shortfall_reason', 500)->nullable()->after('completion_percent');
            $table->string('remarks', 500)->nullable()->after('shortfall_reason');
            $table->boolean('on_hold')->default(false)->after('remarks');
        });

        Schema::table('dpr_materials', function (Blueprint $table) {
            Columns::quantity($table, 'opening_qty')->nullable()->after('material_id');
            Columns::quantity($table, 'received_qty')->nullable()->after('opening_qty');
            Columns::quantity($table, 'issued_qty')->nullable()->after('received_qty');
            Columns::quantity($table, 'closing_qty')->nullable()->after('issued_qty');
            $table->boolean('is_informational')->default(false)->after('remarks');
            $table->string('source', 40)->default('inventory')->after('is_informational'); // inventory | manual
        });

        // Relax materials qty check: informational rows may use derived columns with quantity 0.
        // Existing MySQL check stays; new rows can set quantity to issued_qty or 0.0001 min via service.

        Schema::table('dpr_equipment', function (Blueprint $table) {
            $table->string('operator_name', 120)->nullable()->after('description');
            $table->time('start_time')->nullable()->after('operator_name');
            $table->time('end_time')->nullable()->after('start_time');
            $table->decimal('duration_hours', 8, 2)->nullable()->after('end_time');
            $table->string('status', 40)->nullable()->after('idle_hours');
            $table->string('remarks', 255)->nullable()->after('status');
            $table->boolean('is_informational')->default(true)->after('remarks');
        });

        Schema::table('dpr_labours', function (Blueprint $table) {
            $table->unsignedInteger('skilled')->nullable()->after('headcount');
            $table->unsignedInteger('unskilled')->nullable()->after('skilled');
            $table->unsignedInteger('operator_count')->nullable()->after('unskilled');
            $table->unsignedInteger('helper_count')->nullable()->after('operator_count');
            $table->boolean('is_informational')->default(true)->after('remarks');
            $table->string('source', 40)->default('manual')->after('is_informational'); // attendance | manual
        });

        Schema::create('dpr_constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpr_id')->constrained('dprs')->cascadeOnDelete();
            $table->string('category', 80);
            $table->text('description');
            $table->string('work_area', 160)->nullable();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->string('responsible', 160)->nullable();
            $table->string('priority', 40)->nullable();
            $table->date('raised_date')->nullable();
            $table->date('expected_resolution')->nullable();
            $table->string('status', 40)->default('open');
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['dpr_id', 'status'], 'dpr_constraints_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpr_constraints');

        Schema::table('dpr_labours', function (Blueprint $table) {
            $table->dropColumn(['skilled', 'unskilled', 'operator_count', 'helper_count', 'is_informational', 'source']);
        });

        Schema::table('dpr_equipment', function (Blueprint $table) {
            $table->dropColumn(['operator_name', 'start_time', 'end_time', 'duration_hours', 'status', 'remarks', 'is_informational']);
        });

        Schema::table('dpr_materials', function (Blueprint $table) {
            $table->dropColumn(['opening_qty', 'received_qty', 'issued_qty', 'closing_qty', 'is_informational', 'source']);
        });

        Schema::table('dpr_items', function (Blueprint $table) {
            $table->dropColumn([
                'work_area', 'activity_name_snapshot', 'unit_snapshot', 'planned_start_date', 'planned_end_date',
                'previous_executed_qty', 'today_target_qty', 'hold_quantity', 'hold_reason', 'hold_since',
                'hold_responsible', 'hold_expected_resolution', 'hold_status', 'target_variance',
                'achievement_percent', 'completion_percent', 'shortfall_reason', 'remarks', 'on_hold',
            ]);
        });

        Schema::table('dprs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['source', 'work_performed', 'constraints_summary', 'department', 'agency_name']);
        });
    }
};

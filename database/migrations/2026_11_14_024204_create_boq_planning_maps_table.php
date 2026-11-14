<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Advanced BOQ ↔ Planning quantity mapping.
 * Planning remains createable without BOQ; this stores conversion/allocation when mapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_planning_maps', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('boq_line_uid');
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('mapping_method', 40)->default('conversion_based');
            Columns::quantity($table, 'allocated_boq_qty')->default(0);
            $table->string('boq_unit_symbol', 40)->nullable();
            Columns::quantity($table, 'planning_qty')->default(0);
            $table->string('planning_unit_symbol', 40)->nullable();
            Columns::quantity($table, 'conversion_factor')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['boq_item_id', 'project_task_id']);
            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'boq_item_id']);
            $table->index(['boq_line_uid']);
            $table->index(['project_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_planning_maps');
    }
};

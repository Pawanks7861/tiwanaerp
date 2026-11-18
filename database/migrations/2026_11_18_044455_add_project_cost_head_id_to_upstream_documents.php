<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upstream WBS tags so inheritance can flow BOQ → Planning → MR → … without guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_sections', function (Blueprint $table) {
            $table->foreignId('default_project_cost_head_id')
                ->nullable()
                ->after('sort_order')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('boq_section_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('boq_item_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('material_request_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('task_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('grn_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('purchase_order_item_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('material_issue_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('task_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });
    }

    public function down(): void
    {
        foreach (['material_issue_items', 'grn_items', 'material_request_items', 'project_tasks', 'boq_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('project_cost_head_id');
            });
        }

        Schema::table('boq_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_project_cost_head_id');
        });
    }
};

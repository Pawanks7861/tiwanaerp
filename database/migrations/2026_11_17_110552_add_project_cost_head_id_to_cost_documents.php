<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable Project Cost Head (WBS) on budget lines and cost documents.
 * Historical rows without a head remain valid and surface as Unallocated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_budget_lines', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('cost_head')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('vendor_bill_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('cost_head')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });

        Schema::table('subcontractor_bill_items', function (Blueprint $table) {
            $table->foreignId('project_cost_head_id')
                ->nullable()
                ->after('id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['project_cost_head_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'project_budget_lines',
            'purchase_order_items',
            'work_order_items',
            'vendor_bill_items',
            'expenses',
            'subcontractor_bill_items',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_cost_head_id');
            });
        }
    }
};

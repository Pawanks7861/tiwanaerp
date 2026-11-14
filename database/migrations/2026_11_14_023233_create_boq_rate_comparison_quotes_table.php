<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ — vendor/contractor rate comparison quotes (Sheet2-style).
 * Estimate/comparison only; never posts procurement or finance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_rate_comparison_quotes', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('boq_line_uid');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name', 160);
            Columns::rate($table, 'quoted_rate')->default(0);
            $table->string('gst_mode', 20)->default('inclusive');
            Columns::rate($table, 'gst_percent')->nullable();
            Columns::rate($table, 'effective_rate')->default(0);
            Columns::rate($table, 'internal_cost_rate_snapshot')->default(0);
            Columns::rate($table, 'approved_boq_rate_snapshot')->default(0);
            Columns::rate($table, 'variance_vs_internal')->default(0);
            Columns::percent($table, 'variance_vs_internal_percent')->nullable();
            Columns::rate($table, 'variance_vs_approved')->default(0);
            Columns::percent($table, 'variance_vs_approved_percent')->nullable();
            $table->boolean('is_lowest')->default(false);
            $table->string('remarks', 500)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'boq_item_id']);
            $table->index(['boq_line_uid']);
            $table->index(['vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_rate_comparison_quotes');
    }
};

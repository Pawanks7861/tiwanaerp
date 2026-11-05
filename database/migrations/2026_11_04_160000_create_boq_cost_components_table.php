<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 3 — estimated Cost Build-Up components (no actual ledger side effects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_cost_components', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('boq_line_uid');
            $table->string('component_type', 20);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255);
            Columns::quantity($table, 'consumption_qty')->default(0);
            $table->string('consumption_unit', 40)->nullable();
            Columns::rate($table, 'rate')->default(0);
            Columns::percent($table, 'wastage_percent')->nullable();
            Columns::rate($table, 'amount_per_boq_unit')->default(0);
            $table->string('rate_source', 40)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'boq_item_id']);
            $table->index(['boq_line_uid']);
            $table->index(['component_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_cost_components');
    }
};

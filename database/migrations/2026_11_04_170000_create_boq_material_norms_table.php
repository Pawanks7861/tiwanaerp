<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 4 — planned material norms (no stock / ledger / Tally side effects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_material_norms', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('boq_line_uid');
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::quantity($table, 'consumption_qty')->default(0);
            $table->string('consumption_unit', 40);
            Columns::percent($table, 'wastage_percent')->default(0);
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['boq_item_id', 'material_id', 'consumption_unit'], 'boq_mat_norms_item_mat_unit_uq');
            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'boq_item_id']);
            $table->index(['boq_line_uid']);
            $table->index(['material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_material_norms');
    }
};

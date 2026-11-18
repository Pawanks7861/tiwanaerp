<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional company mapping: Material Category → default Project Cost Head (WBS).
 * Never inferred from free-text descriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_category_wbs_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_category_id')->constrained('material_categories')->cascadeOnDelete();
            $table->foreignId('project_cost_head_id')->constrained('project_cost_heads')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'material_category_id'], 'mat_cat_wbs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_category_wbs_maps');
    }
};

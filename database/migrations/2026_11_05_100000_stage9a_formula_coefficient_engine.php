<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 9A — Formula & Coefficient Engine (planning/calculation only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_recipe_templates', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('material_recipe_templates')->nullOnDelete();
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['company_id', 'code', 'version']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'project_id']);
        });

        Schema::create('material_recipe_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('material_recipe_templates')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::quantity($table, 'consumption_qty')->default(0);
            $table->string('consumption_unit', 40);
            Columns::percent($table, 'wastage_percent')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['recipe_id', 'sequence']);
            $table->unique(['recipe_id', 'material_id', 'consumption_unit'], 'recipe_lines_unique_material_unit');
        });

        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('from_unit', 40);
            $table->string('to_unit', 40);
            $table->string('operation', 20); // multiply | divide
            Columns::rate($table, 'factor');
            $table->string('description', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'from_unit', 'to_unit']);
            $table->index(['material_id', 'is_active']);
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('steel_unit_weight_profiles', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('name', 160);
            $table->string('method', 40)->default('configured_table');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['company_id', 'name', 'version']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('steel_unit_weight_profile_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('steel_unit_weight_profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('diameter_mm');
            Columns::rate($table, 'weight_kg_per_m');
            $table->timestamps();

            $table->unique(['profile_id', 'diameter_mm']);
        });

        Schema::create('costing_rate_library_entries', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('entry_type', 40); // material | labour | equipment | subcontract | other
            $table->string('description', 255);
            $table->string('unit', 40)->nullable();
            Columns::rate($table, 'rate');
            $table->date('effective_from')->nullable();
            $table->string('source', 160)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'entry_type', 'is_active'], 'rate_lib_co_type_active_idx');
            $table->index(['project_id', 'is_active'], 'rate_lib_proj_active_idx');
        });

        Schema::create('boq_bbs_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bbs_id')->constrained('boq_bbs')->cascadeOnDelete();
            $table->unsignedSmallInteger('diameter_mm')->nullable();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            Columns::quantity($table, 'adjustment_qty_kg');
            $table->string('reason', 500);
            $table->string('reference', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['bbs_id']);
            $table->index(['bbs_id', 'diameter_mm']);
        });

        Schema::table('boq_cost_components', function (Blueprint $table) {
            $table->string('calculation_basis', 40)->default('quantity_rate')->after('description');
            Columns::rate($table, 'base_rate')->nullable()->after('rate');
            Columns::rate($table, 'factor')->nullable()->after('base_rate');
            $table->string('factor_reference', 120)->nullable()->after('factor');
            Columns::percent($table, 'percent_value')->nullable()->after('factor_reference');
            Columns::rate($table, 'calculated_rate')->nullable()->after('percent_value');
            $table->string('derivation_trace', 500)->nullable()->after('calculated_rate');
            $table->foreignId('rate_library_id')->nullable()->after('derivation_trace')
                ->constrained('costing_rate_library_entries')->nullOnDelete();
            $table->json('conversion_snapshot')->nullable()->after('rate_library_id');
        });

        Schema::table('boq_material_norms', function (Blueprint $table) {
            $table->foreignId('recipe_template_id')->nullable()->after('notes')
                ->constrained('material_recipe_templates')->nullOnDelete();
            $table->unsignedInteger('recipe_version')->nullable()->after('recipe_template_id');
            $table->string('recipe_code', 40)->nullable()->after('recipe_version');
        });

        Schema::table('boq_bbs', function (Blueprint $table) {
            $table->string('unit_weight_method', 40)->default('formula_d2_162')->after('wastage_percent');
            $table->foreignId('steel_profile_id')->nullable()->after('unit_weight_method')
                ->constrained('steel_unit_weight_profiles')->nullOnDelete();
            $table->string('bar_count_rounding', 40)->default('ceil')->after('steel_profile_id');
            Columns::quantity($table, 'adjusted_theoretical_weight')->default(0)->after('total_theoretical_weight');
            $table->json('unit_weight_profile_snapshot')->nullable()->after('required_weight');
        });

        Schema::table('boq_bbs_lines', function (Blueprint $table) {
            $table->string('count_mode', 20)->default('manual')->after('bars_per_member');
            Columns::quantity($table, 'distribution_length')->nullable()->after('count_mode');
            Columns::quantity($table, 'start_cover')->nullable()->after('distribution_length');
            Columns::quantity($table, 'end_cover')->nullable()->after('start_cover');
            Columns::quantity($table, 'spacing')->nullable()->after('end_cover');
            $table->boolean('include_end_bar')->default(true)->after('spacing');
            $table->unsignedInteger('faces_layers_multiplier')->default(1)->after('include_end_bar');
            $table->string('bar_count_rounding', 40)->nullable()->after('faces_layers_multiplier');
            $table->string('lap_mode', 20)->default('manual')->after('bend_deduction');
            Columns::rate($table, 'lap_diameter_factor')->nullable()->after('lap_mode');
            Columns::quantity($table, 'lap_length')->default(0)->after('lap_diameter_factor');
            Columns::quantity($table, 'lap_count_per_bar')->default(0)->after('lap_length');
            Columns::rate($table, 'unit_weight_override')->nullable()->after('unit_weight');
            $table->string('unit_weight_override_reason', 500)->nullable()->after('unit_weight_override');
            $table->foreignId('unit_weight_override_by')->nullable()->after('unit_weight_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('unit_weight_override_at')->nullable()->after('unit_weight_override_by');
        });

        Schema::table('boq_measurement_sheets', function (Blueprint $table) {
            $table->string('circle_constant_mode', 40)->default('mathematical_pi')->after('total_quantity');
            Columns::rate($table, 'circle_constant_value')->nullable()->after('circle_constant_mode');
            $table->json('calculation_profile_snapshot')->nullable()->after('circle_constant_value');
        });
    }

    public function down(): void
    {
        Schema::table('boq_measurement_sheets', function (Blueprint $table) {
            $table->dropColumn(['circle_constant_mode', 'circle_constant_value', 'calculation_profile_snapshot']);
        });

        Schema::table('boq_bbs_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_weight_override_by');
            $table->dropColumn([
                'count_mode', 'distribution_length', 'start_cover', 'end_cover', 'spacing',
                'include_end_bar', 'faces_layers_multiplier', 'bar_count_rounding',
                'lap_mode', 'lap_diameter_factor', 'lap_length', 'lap_count_per_bar',
                'unit_weight_override', 'unit_weight_override_reason', 'unit_weight_override_at',
            ]);
        });

        Schema::table('boq_bbs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('steel_profile_id');
            $table->dropColumn([
                'unit_weight_method', 'bar_count_rounding', 'adjusted_theoretical_weight',
                'unit_weight_profile_snapshot',
            ]);
        });

        Schema::table('boq_material_norms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipe_template_id');
            $table->dropColumn(['recipe_version', 'recipe_code']);
        });

        Schema::table('boq_cost_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_library_id');
            $table->dropColumn([
                'calculation_basis', 'base_rate', 'factor', 'factor_reference', 'percent_value',
                'calculated_rate', 'derivation_trace', 'conversion_snapshot',
            ]);
        });

        Schema::dropIfExists('boq_bbs_adjustments');
        Schema::dropIfExists('costing_rate_library_entries');
        Schema::dropIfExists('steel_unit_weight_profile_lines');
        Schema::dropIfExists('steel_unit_weight_profiles');
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('material_recipe_template_lines');
        Schema::dropIfExists('material_recipe_templates');
    }
};

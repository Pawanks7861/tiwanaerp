<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concrete_mix_profiles', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 120);
            $table->string('concrete_type', 40);
            $table->string('grade', 40)->nullable();
            $table->string('boq_unit_symbol', 20)->default('Cum');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('remarks', 500)->nullable();
            Columns::blame($table, false);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'cmp_company_name_uq');
            $table->index(['company_id', 'is_active', 'sort_order'], 'cmp_company_active_sort_idx');
        });

        Schema::create('concrete_mix_profile_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concrete_mix_profile_id')->constrained('concrete_mix_profiles')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('material_name', 160);
            Columns::quantity($table, 'factor');
            $table->string('factor_unit', 40);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['concrete_mix_profile_id', 'sort_order'], 'cmp_lines_profile_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concrete_mix_profile_lines');
        Schema::dropIfExists('concrete_mix_profiles');
    }
};

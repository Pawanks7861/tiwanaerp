<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boqs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('boq_number', 30);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_boq_id')->nullable()->constrained('boqs')->nullOnDelete();
            $table->string('title', 200);
            $table->string('status', 30)->default('draft');
            $table->boolean('is_current')->default(false);
            Columns::money($table, 'total_cost_amount')->default(0);
            Columns::money($table, 'total_client_amount')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'boq_number', 'version']);
            $table->index(['project_id', 'is_current']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('boq_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('boq_sections')->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('name', 200);
            $table->string('discipline', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['boq_id', 'parent_id']);
        });

        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_section_id')->constrained('boq_sections')->cascadeOnDelete();
            $table->uuid('line_uid');
            $table->string('item_code', 30)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity')->default(0);
            Columns::rate($table, 'material_rate')->default(0);
            Columns::rate($table, 'labour_rate')->default(0);
            Columns::rate($table, 'equipment_rate')->default(0);
            Columns::rate($table, 'subcontract_rate')->default(0);
            Columns::rate($table, 'cost_rate')->default(0);
            Columns::money($table, 'cost_amount')->default(0);
            Columns::percent($table, 'margin_percent')->default(0);
            Columns::rate($table, 'selling_rate')->default(0);
            Columns::rate($table, 'client_rate')->default(0);
            Columns::money($table, 'client_amount')->default(0);
            $table->foreignId('rate_analysis_id')->nullable()->constrained('rate_analyses')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['boq_id', 'boq_section_id']);
            $table->index('line_uid');
            $table->unique(['boq_id', 'line_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('boq_sections');
        Schema::dropIfExists('boqs');
    }
};

<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_analyses', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'output_quantity')->default(1);
            Columns::percent($table, 'overhead_percent')->default(0);
            Columns::percent($table, 'profit_percent')->default(0);
            Columns::money($table, 'material_cost')->default(0);
            Columns::money($table, 'labour_cost')->default(0);
            Columns::money($table, 'equipment_cost')->default(0);
            Columns::money($table, 'subcontract_cost')->default(0);
            Columns::money($table, 'other_cost')->default(0);
            Columns::money($table, 'overhead_amount')->default(0);
            Columns::money($table, 'profit_amount')->default(0);
            Columns::money($table, 'total_cost')->default(0);
            Columns::rate($table, 'unit_rate')->default(0);
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'project_id', 'status']);
        });

        Schema::create('rate_analysis_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_analysis_id')->constrained('rate_analyses')->cascadeOnDelete();
            $table->string('resource_type', 20);
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();
            $table->foreignId('labour_trade_id')->nullable()->constrained('labour_trades')->restrictOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->restrictOnDelete();
            $table->string('description', 255);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity')->default(0);
            Columns::percent($table, 'wastage_percent')->default(0);
            Columns::rate($table, 'rate')->default(0);
            Columns::money($table, 'amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['rate_analysis_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_analysis_items');
        Schema::dropIfExists('rate_analyses');
    }
};

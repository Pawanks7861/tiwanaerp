<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 1–2: quantity source + measurement sheets/lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->string('quantity_source', 20)->default('direct')->after('quantity');
            $table->string('takeoff_reference', 120)->nullable()->after('quantity_source');
        });

        Schema::create('boq_measurement_sheets', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('line_uid');
            $table->foreignId('drawing_revision_id')->nullable()->constrained('drawing_revisions')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            Columns::quantity($table, 'total_quantity')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'boq_item_id']);
            $table->index(['line_uid']);
            $table->index(['status']);
        });

        Schema::create('boq_measurement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_measurement_sheet_id')->constrained('boq_measurement_sheets')->cascadeOnDelete();
            $table->string('location', 160)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('formula_type', 40);
            Columns::quantity($table, 'nos')->nullable();
            Columns::quantity($table, 'length')->nullable();
            Columns::quantity($table, 'width')->nullable();
            Columns::quantity($table, 'height_depth')->nullable();
            Columns::quantity($table, 'diameter')->nullable();
            Columns::quantity($table, 'radius')->nullable();
            Columns::quantity($table, 'manual_quantity')->nullable();
            Columns::quantity($table, 'addition_quantity')->default(0);
            Columns::quantity($table, 'deduction_quantity')->default(0);
            Columns::quantity($table, 'calculated_base')->default(0);
            Columns::quantity($table, 'calculated_quantity')->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('drawing_revision_id')->nullable()->constrained('drawing_revisions')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['boq_measurement_sheet_id', 'sort_order']);
            $table->index(['location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_measurement_lines');
        Schema::dropIfExists('boq_measurement_sheets');

        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_source', 'takeoff_reference']);
        });
    }
};

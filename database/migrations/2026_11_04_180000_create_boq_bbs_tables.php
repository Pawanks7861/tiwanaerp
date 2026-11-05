<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 5 — Bar Bending Schedule (planned steel only; no stock/ledger/Tally).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->boolean('bbs_applicable')->default(false)->after('takeoff_reference');
        });

        Schema::create('boq_bbs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->uuid('boq_line_uid');
            $table->foreignId('drawing_revision_id')->nullable()->constrained('drawing_revisions')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            Columns::percent($table, 'wastage_percent')->default(0);
            Columns::quantity($table, 'total_theoretical_weight')->default(0); // kg
            Columns::quantity($table, 'wastage_weight')->default(0); // kg
            Columns::quantity($table, 'required_weight')->default(0); // kg
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['boq_item_id']);
            $table->index(['company_id', 'project_id']);
            $table->index(['boq_id', 'status']);
            $table->index(['boq_line_uid']);
        });

        Schema::create('boq_bbs_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bbs_id')->constrained('boq_bbs')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('structure_location', 160)->nullable();
            $table->string('bar_mark', 40);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('diameter_mm');
            $table->unsignedInteger('number_of_members')->default(0);
            $table->unsignedInteger('bars_per_member')->default(0);
            $table->unsignedInteger('total_bars')->default(0);
            $table->string('shape_code', 30);
            // Dimension inputs stored in meters (4 dp).
            Columns::quantity($table, 'a_length')->nullable();
            Columns::quantity($table, 'b_length')->nullable();
            Columns::quantity($table, 'c_length')->nullable();
            Columns::quantity($table, 'd_length')->nullable();
            Columns::quantity($table, 'e_length')->nullable();
            Columns::quantity($table, 'hook_length')->default(0);
            Columns::quantity($table, 'bend_deduction')->default(0);
            Columns::quantity($table, 'cutting_length')->default(0); // m
            Columns::quantity($table, 'total_length')->default(0); // m
            Columns::rate($table, 'unit_weight')->default(0); // kg/m
            Columns::quantity($table, 'theoretical_weight')->default(0); // kg
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['bbs_id', 'sequence']);
            $table->index(['bbs_id', 'diameter_mm']);
        });

        Schema::create('boq_bbs_steel_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bbs_id')->constrained('boq_bbs')->cascadeOnDelete();
            $table->unsignedSmallInteger('diameter_mm');
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['bbs_id', 'diameter_mm']);
            $table->index(['material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_bbs_steel_maps');
        Schema::dropIfExists('boq_bbs_lines');
        Schema::dropIfExists('boq_bbs');
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn('bbs_applicable');
        });
    }
};

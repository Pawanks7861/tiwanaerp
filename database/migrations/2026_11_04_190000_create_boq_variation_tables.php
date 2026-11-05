<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced BOQ Stage 8 — Variation / Extra Items.
 *
 * Original approved BOQ rows are never rewritten. Effective scope =
 * Original Approved Qty + Σ(Approved variation quantity changes).
 * New items are materialized onto boq_items only on approval (via service),
 * tagged with origin_variation_item_id for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_variations', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->restrictOnDelete();
            $table->string('variation_number', 40);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('change_order_type', 40)->default('scope_change');
            $table->string('source_type', 40);
            $table->string('source_reference', 120)->nullable();
            $table->foreignId('drawing_revision_id')->nullable()->constrained('drawing_revisions')->nullOnDelete();
            $table->string('client_instruction_reference', 120)->nullable();
            $table->date('client_instruction_date')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            Columns::money($table, 'revenue_impact')->default(0);
            Columns::money($table, 'cost_impact')->default(0);
            Columns::money($table, 'margin_impact')->default(0);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'variation_number']);
            $table->index(['project_id', 'status']);
            $table->index(['boq_id', 'status']);
            $table->index(['company_id', 'project_id', 'status']);
        });

        Schema::create('boq_variation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('boq_variations')->cascadeOnDelete();
            $table->foreignId('original_boq_item_id')->nullable()->constrained('boq_items')->restrictOnDelete();
            $table->uuid('original_line_uid')->nullable();
            $table->uuid('variation_item_uid');
            $table->string('change_type', 40);
            $table->string('item_code', 30)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignId('boq_section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->string('quantity_source', 20)->default('direct');
            $table->string('takeoff_reference', 120)->nullable();
            Columns::quantity($table, 'original_quantity')->default(0);
            Columns::quantity($table, 'quantity_change')->default(0);
            Columns::quantity($table, 'revised_quantity')->default(0);
            Columns::rate($table, 'original_selling_rate')->default(0);
            Columns::rate($table, 'proposed_selling_rate')->default(0);
            Columns::rate($table, 'original_cost_rate')->default(0);
            Columns::rate($table, 'proposed_cost_rate')->default(0);
            Columns::percent($table, 'gst_rate')->default(0);
            Columns::money($table, 'revenue_impact')->default(0);
            Columns::money($table, 'cost_impact')->default(0);
            Columns::money($table, 'margin_impact')->default(0);
            $table->text('notes')->nullable();
            $table->json('measurement_payload')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('materialized_boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->timestamps();

            $table->unique(['variation_id', 'variation_item_uid']);
            $table->index(['original_line_uid']);
            $table->index(['original_boq_item_id']);
            $table->index(['change_type']);
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->foreignId('origin_variation_item_id')->nullable()->after('rate_analysis_id')
                ->constrained('boq_variation_items')->nullOnDelete();
            $table->boolean('is_variation_originated')->default(false)->after('origin_variation_item_id');
            $table->index(['is_variation_originated']);
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_variation_item_id');
            $table->dropColumn('is_variation_originated');
        });
        Schema::dropIfExists('boq_variation_items');
        Schema::dropIfExists('boq_variations');
    }
};

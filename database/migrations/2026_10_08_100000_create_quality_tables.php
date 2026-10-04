<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 quality (architecture H.15): reusable checklist templates, inspections that copy the
 * checkpoints at creation (so later template edits never rewrite history) and NCRs.
 * Inspection: requested → scheduled → completed (result only when completed).
 * NCR: open → in_progress → resolved → verified → closed (resolved may go back to in_progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_checklists', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('name', 150);
            $table->string('discipline', 30);
            $table->string('activity', 150)->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('quality_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_checklist_id')->constrained('quality_checklists')->cascadeOnDelete();
            $table->string('checkpoint', 255);
            $table->string('acceptance_criteria', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quality_checklist_id', 'sort_order']);
        });

        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('inspection_number', 40);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->foreignId('quality_checklist_id')->constrained('quality_checklists')->restrictOnDelete();
            $table->string('request_notes', 1000)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('inspection_date')->nullable();
            $table->foreignId('engineer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result', 20)->nullable();
            $table->string('remarks', 2000)->nullable();
            $table->string('status', 20);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'inspection_number']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'inspection_date']);
            $table->index('quality_checklist_id');
        });

        Schema::create('quality_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_inspection_id')->constrained('quality_inspections')->cascadeOnDelete();
            $table->foreignId('quality_checklist_item_id')->nullable()->constrained('quality_checklist_items')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('checkpoint', 255);
            $table->string('acceptance_criteria', 500)->nullable();
            $table->string('result', 10)->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();

            $table->index(['quality_inspection_id', 'sort_order']);
        });

        Schema::create('ncrs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('quality_inspection_id')->nullable()->constrained('quality_inspections')->restrictOnDelete();
            $table->string('ncr_number', 40);
            $table->string('issue', 2000);
            $table->string('location', 255)->nullable();
            $table->string('severity', 20);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            $table->date('target_date')->nullable();
            $table->string('root_cause', 2000)->nullable();
            $table->string('corrective_action', 2000)->nullable();
            $table->string('status', 20);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_remarks', 1000)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'ncr_number']);
            $table->index(['project_id', 'status']);
            $table->index('quality_inspection_id');
            $table->index('responsible_user_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE quality_checklists ADD CONSTRAINT quality_checklists_check CHECK (CHAR_LENGTH(TRIM(name)) > 0)');
            DB::statement("ALTER TABLE quality_inspections ADD CONSTRAINT quality_inspections_check CHECK (status IN ('requested', 'scheduled', 'completed') AND (result IS NULL OR result IN ('passed', 'failed', 'conditional')) AND ((status = 'completed') = (result IS NOT NULL AND completed_at IS NOT NULL AND inspection_date IS NOT NULL)) AND (status = 'requested' OR inspection_date IS NOT NULL))");
            DB::statement("ALTER TABLE quality_inspection_items ADD CONSTRAINT quality_inspection_items_check CHECK ((result IS NULL OR result IN ('pass', 'fail', 'na')) AND (result IS NULL OR result <> 'fail' OR remark IS NOT NULL))");
            DB::statement("ALTER TABLE ncrs ADD CONSTRAINT ncrs_check CHECK (status IN ('open', 'in_progress', 'resolved', 'verified', 'closed') AND severity IN ('minor', 'major', 'critical') AND (status NOT IN ('resolved', 'verified', 'closed') OR (root_cause IS NOT NULL AND corrective_action IS NOT NULL AND resolved_at IS NOT NULL)) AND (status NOT IN ('verified', 'closed') OR verified_at IS NOT NULL) AND (status <> 'closed' OR closed_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ncrs');
        Schema::dropIfExists('quality_inspection_items');
        Schema::dropIfExists('quality_inspections');
        Schema::dropIfExists('quality_checklist_items');
        Schema::dropIfExists('quality_checklists');
    }
};

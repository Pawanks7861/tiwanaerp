<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily progress report (architecture H.10): one per project per day, pre-filled from that
        // day's approved site diaries. Its approval is the only writer of progress_entries.
        Schema::create('dprs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('dpr_number', 40);
            $table->date('dpr_date');
            $table->string('weather', 150)->nullable();
            $table->text('site_issues')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('engineer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            // Incremented each time an approved DPR is reopened for correction (postings reversed).
            $table->unsignedSmallInteger('revision')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'dpr_date']);
            $table->unique(['company_id', 'dpr_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('dpr_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpr_id')->constrained('dprs')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->restrictOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->restrictOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // Server snapshots: refreshed while the DPR is a draft and frozen at approval.
            Columns::quantity($table, 'planned_qty')->nullable();
            Columns::quantity($table, 'executed_qty');
            Columns::quantity($table, 'cumulative_qty')->nullable();
            Columns::quantity($table, 'balance_qty')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('task_id');
            $table->index('boq_line_uid');
        });

        Schema::create('dpr_labours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpr_id')->constrained('dprs')->cascadeOnDelete();
            $table->foreignId('labour_trade_id')->constrained('labour_trades')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            $table->unsignedInteger('headcount');
            $table->decimal('hours', 8, 2)->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('dpr_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpr_id')->constrained('dprs')->cascadeOnDelete();
            $table->unsignedBigInteger('equipment_id')->nullable();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->restrictOnDelete();
            $table->string('description', 150)->nullable();
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('idle_hours', 8, 2)->default(0);
            $table->timestamps();

            $table->index('equipment_id');
        });

        Schema::create('dpr_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpr_id')->constrained('dprs')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE dpr_items ADD CONSTRAINT dpr_items_qty_check CHECK (executed_qty >= 0 AND (planned_qty IS NULL OR planned_qty >= 0))');
            DB::statement('ALTER TABLE dpr_labours ADD CONSTRAINT dpr_labours_check CHECK (headcount > 0 AND hours >= 0)');
            DB::statement('ALTER TABLE dpr_equipment ADD CONSTRAINT dpr_equipment_hours_check CHECK (working_hours >= 0 AND idle_hours >= 0)');
            DB::statement('ALTER TABLE dpr_materials ADD CONSTRAINT dpr_materials_qty_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dpr_materials');
        Schema::dropIfExists('dpr_equipment');
        Schema::dropIfExists('dpr_labours');
        Schema::dropIfExists('dpr_items');
        Schema::dropIfExists('dprs');
    }
};

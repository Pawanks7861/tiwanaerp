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
        // Site diary (architecture H.10): several per project per day; never posts progress itself.
        Schema::create('site_diaries', function (Blueprint $table) {
            $table->id();
            // Client-generated for idempotent mobile retries; the bigint id stays the key.
            $table->uuid('uuid')->unique();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->date('diary_date');
            $table->string('weather', 50)->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->string('work_location', 150)->nullable();
            $table->text('work_performed')->nullable();
            $table->text('issues')->nullable();
            $table->text('safety_incidents')->nullable();
            $table->text('remarks')->nullable();
            Columns::geo($table);
            $table->timestamp('captured_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'diary_date']);
            $table->index(['created_by', 'diary_date']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('site_diary_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();

            $table->index('task_id');
        });

        // Informational headcount only: no attendance, wages or payroll in Phase 5.
        Schema::create('site_diary_labours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->foreignId('labour_trade_id')->constrained('labour_trades')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            $table->unsignedInteger('headcount');
            $table->decimal('hours', 8, 2)->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });

        // The equipment register is Phase 6; until then lines name an equipment type (master) and
        // an optional free-text description. equipment_id gets its FK when the equipment table exists.
        Schema::create('site_diary_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->unsignedBigInteger('equipment_id')->nullable();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->restrictOnDelete();
            $table->string('description', 150)->nullable();
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('idle_hours', 8, 2)->default(0);
            $table->timestamps();

            $table->index('equipment_id');
        });

        // Material consumption as reported from site. Never posts stock or cost: the stock ledger
        // already moved when the material was issued from the store.
        Schema::create('site_diary_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('site_diary_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('mime', 100);
            $table->string('caption', 255)->nullable();
            Columns::geo($table);
            $table->timestamp('taken_at')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE site_diary_work_items ADD CONSTRAINT site_diary_work_items_qty_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE site_diary_labours ADD CONSTRAINT site_diary_labours_check CHECK (headcount > 0 AND hours >= 0)');
            DB::statement('ALTER TABLE site_diary_equipment ADD CONSTRAINT site_diary_equipment_hours_check CHECK (working_hours >= 0 AND idle_hours >= 0)');
            DB::statement('ALTER TABLE site_diary_materials ADD CONSTRAINT site_diary_materials_qty_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_diary_photos');
        Schema::dropIfExists('site_diary_materials');
        Schema::dropIfExists('site_diary_equipment');
        Schema::dropIfExists('site_diary_labours');
        Schema::dropIfExists('site_diary_work_items');
        Schema::dropIfExists('site_diaries');
    }
};

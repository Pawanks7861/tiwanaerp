<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_usage_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('equipment_usage_logs', 'start_time')) {
                $table->time('start_time')->nullable()->after('log_date');
            }
            if (! Schema::hasColumn('equipment_usage_logs', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }
            if (! Schema::hasColumn('equipment_usage_logs', 'total_duration_hours')) {
                $table->decimal('total_duration_hours', 8, 2)->nullable()->after('end_time');
            }
        });

        Schema::table('equipment', function (Blueprint $table) {
            if (! Schema::hasColumn('equipment', 'tracking_mode')) {
                $table->string('tracking_mode', 20)->default('individual')->after('is_active');
            }
            if (! Schema::hasColumn('equipment', 'opening_quantity')) {
                $table->decimal('opening_quantity', 18, 4)->default(0)->after('tracking_mode');
            }
            if (! Schema::hasColumn('equipment', 'available_quantity')) {
                $table->decimal('available_quantity', 18, 4)->default(0)->after('opening_quantity');
            }
        });

        if (! Schema::hasTable('equipment_quantity_movements')) {
            Schema::create('equipment_quantity_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
                $table->date('moved_at');
                $table->string('movement_type', 30);
                $table->decimal('quantity', 18, 4);
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->string('reference_type', 80)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('remarks', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['equipment_id', 'moved_at']);
                $table->index(['company_id', 'moved_at']);
            });
        }

        Schema::table('boqs', function (Blueprint $table) {
            if (! Schema::hasColumn('boqs', 'revision_reason')) {
                $table->string('revision_reason', 255)->nullable()->after('title');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE equipment_usage_logs DROP CHECK equipment_usage_logs_check');
            } catch (\Throwable) {
                // Constraint name may differ on older DBs.
            }
            DB::statement('ALTER TABLE equipment_usage_logs ADD CONSTRAINT equipment_usage_logs_check CHECK (working_hours >= 0 AND idle_hours >= 0 AND working_hours + idle_hours <= 24 AND (opening_meter IS NULL OR closing_meter IS NULL OR closing_meter >= opening_meter) AND (total_duration_hours IS NULL OR total_duration_hours >= 0))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE equipment_usage_logs DROP CHECK equipment_usage_logs_check');
            } catch (\Throwable) {
            }
            DB::statement('ALTER TABLE equipment_usage_logs ADD CONSTRAINT equipment_usage_logs_check CHECK (working_hours >= 0 AND idle_hours >= 0 AND working_hours + idle_hours <= 24 AND (opening_meter IS NULL OR closing_meter IS NULL OR closing_meter >= opening_meter))');
        }

        Schema::table('boqs', function (Blueprint $table) {
            if (Schema::hasColumn('boqs', 'revision_reason')) {
                $table->dropColumn('revision_reason');
            }
        });

        Schema::dropIfExists('equipment_quantity_movements');

        Schema::table('equipment', function (Blueprint $table) {
            foreach (['available_quantity', 'opening_quantity', 'tracking_mode'] as $col) {
                if (Schema::hasColumn('equipment', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('equipment_usage_logs', function (Blueprint $table) {
            foreach (['total_duration_hours', 'end_time', 'start_time'] as $col) {
                if (Schema::hasColumn('equipment_usage_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

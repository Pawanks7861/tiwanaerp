<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 equipment (architecture H.13): register, project assignments, daily usage logs (the
 * posted log is the 'equipment' project cost), fuel logs and repairs (recorded, not costed here:
 * fuel and repair bills reach the cost ledger through Phase 7 expenses / vendor bills).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('equipment_type_id')->constrained('equipment_types')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('ownership', 20);
            $table->foreignId('owner_vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->string('registration_no', 50)->nullable();
            $table->date('purchase_date')->nullable();
            Columns::money($table, 'purchase_value')->nullable();
            Columns::rate($table, 'hourly_rate')->default(0);
            Columns::rate($table, 'daily_rate')->default(0);
            $table->string('status', 20);
            $table->boolean('is_active')->default(true);
            $table->string('remarks', 1000)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->date('issue_date');
            $table->date('return_date')->nullable();
            $table->foreignId('operator_labour_id')->nullable()->constrained('labours')->restrictOnDelete();
            $table->string('operator_name', 150)->nullable();
            $table->string('rate_basis', 10);
            Columns::rate($table, 'rate');
            $table->string('status', 20);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'issue_date']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('equipment_usage_logs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('equipment_assignment_id')->constrained('equipment_assignments')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->date('log_date');
            $table->decimal('opening_meter', 12, 2)->nullable();
            $table->decimal('closing_meter', 12, 2)->nullable();
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('idle_hours', 8, 2)->default(0);
            Columns::money($table, 'cost_amount')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['equipment_assignment_id', 'log_date']);
            $table->index(['project_id', 'log_date']);
        });

        Schema::create('equipment_fuel_logs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->date('log_date');
            $table->decimal('opening_fuel', 12, 2)->default(0);
            $table->decimal('fuel_added', 12, 2)->default(0);
            $table->decimal('fuel_consumed', 12, 2)->default(0);
            $table->decimal('closing_fuel', 12, 2)->default(0);
            Columns::rate($table, 'fuel_rate')->default(0);
            Columns::money($table, 'cost')->default(0);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'log_date']);
            $table->index(['project_id', 'log_date']);
        });

        Schema::create('equipment_repairs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->date('repair_date');
            $table->string('description', 1000);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            Columns::money($table, 'cost')->default(0);
            // Phase 7 links the repair bill here; there is no expenses table yet, so no FK.
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->string('status', 20);
            $table->date('completed_date')->nullable();
            $table->string('remarks', 500)->nullable();
            Columns::blame($table, false);
            $table->timestamps();

            $table->index('equipment_id');
            $table->index(['project_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE equipment ADD CONSTRAINT equipment_check CHECK (hourly_rate >= 0 AND daily_rate >= 0 AND ownership IN ('owned','hired') AND status IN ('available','assigned','under_repair','disposed'))");
            DB::statement("ALTER TABLE equipment_assignments ADD CONSTRAINT equipment_assignments_check CHECK (rate >= 0 AND rate_basis IN ('hourly','daily') AND (return_date IS NULL OR return_date >= issue_date))");
            DB::statement('ALTER TABLE equipment_usage_logs ADD CONSTRAINT equipment_usage_logs_check CHECK (working_hours >= 0 AND idle_hours >= 0 AND working_hours + idle_hours <= 24 AND (opening_meter IS NULL OR closing_meter IS NULL OR closing_meter >= opening_meter))');
            DB::statement('ALTER TABLE equipment_fuel_logs ADD CONSTRAINT equipment_fuel_logs_check CHECK (opening_fuel >= 0 AND fuel_added >= 0 AND fuel_consumed >= 0 AND closing_fuel >= 0 AND fuel_rate >= 0 AND cost >= 0)');
            DB::statement('ALTER TABLE equipment_repairs ADD CONSTRAINT equipment_repairs_check CHECK (cost >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_repairs');
        Schema::dropIfExists('equipment_fuel_logs');
        Schema::dropIfExists('equipment_usage_logs');
        Schema::dropIfExists('equipment_assignments');
        Schema::dropIfExists('equipment');
    }
};

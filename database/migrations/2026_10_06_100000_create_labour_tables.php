<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 labour (architecture H.11): labour register, daily attendance with wage snapshots,
 * advances and payment batches. Approved attendance is the only labour cost posting; payments
 * settle the liability and never post cost again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labours', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            Columns::company($table);
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('mobile', 20)->nullable();
            $table->foreignId('labour_trade_id')->constrained('labour_trades')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            Columns::money($table, 'daily_wage');
            Columns::rate($table, 'ot_rate_per_hour')->default(0);
            $table->foreignId('current_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('joining_date')->nullable();
            $table->string('id_proof_type', 30)->nullable();
            $table->string('id_proof_no', 50)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index('current_project_id');
            $table->index(['company_id', 'name']);
        });

        Schema::create('labour_payments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('payment_number', 40);
            $table->date('period_from');
            $table->date('period_to');
            Columns::money($table, 'total_gross')->default(0);
            Columns::money($table, 'total_ot')->default(0);
            Columns::money($table, 'total_deductions')->default(0);
            Columns::money($table, 'total_net')->default(0);
            $table->string('status', 30);
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('return_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'payment_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('labour_attendance', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('labour_id')->constrained('labours')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20);
            $table->time('punch_in')->nullable();
            $table->time('punch_out')->nullable();
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('ot_hours', 8, 2)->default(0);
            Columns::money($table, 'daily_wage');
            Columns::rate($table, 'ot_rate');
            Columns::money($table, 'wage_amount');
            Columns::money($table, 'ot_amount');
            Columns::geo($table);
            $table->string('photo_path')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->string('approval_status', 20);
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('labour_payment_id')->nullable()->constrained('labour_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['labour_id', 'attendance_date']);
            $table->index(['project_id', 'attendance_date']);
            $table->index(['project_id', 'approval_status']);
        });

        Schema::create('labour_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_payment_id')->constrained('labour_payments')->cascadeOnDelete();
            $table->foreignId('labour_id')->constrained('labours')->restrictOnDelete();
            $table->decimal('present_days', 8, 1)->default(0);
            $table->decimal('half_days', 8, 1)->default(0);
            $table->decimal('ot_hours', 10, 2)->default(0);
            Columns::money($table, 'gross_wage');
            Columns::money($table, 'ot_amount');
            Columns::money($table, 'advance_recovery')->default(0);
            Columns::money($table, 'other_deductions')->default(0);
            Columns::money($table, 'net_amount');
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->unique(['labour_payment_id', 'labour_id']);
            $table->index('labour_id');
        });

        Schema::create('labour_advances', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('labour_id')->constrained('labours')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->date('advance_date');
            Columns::money($table, 'amount');
            Columns::money($table, 'recovered_amount')->default(0);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('labour_id');
            $table->index(['project_id', 'advance_date']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE labours ADD CONSTRAINT labours_rates_check CHECK (daily_wage >= 0 AND ot_rate_per_hour >= 0)');
            DB::statement("ALTER TABLE labour_attendance ADD CONSTRAINT labour_attendance_check CHECK (working_hours >= 0 AND working_hours <= 24 AND ot_hours >= 0 AND ot_hours <= 24 AND wage_amount >= 0 AND ot_amount >= 0 AND status IN ('present','absent','half_day','leave'))");
            DB::statement('ALTER TABLE labour_payment_lines ADD CONSTRAINT labour_payment_lines_check CHECK (gross_wage >= 0 AND ot_amount >= 0 AND advance_recovery >= 0 AND other_deductions >= 0 AND net_amount >= 0)');
            DB::statement('ALTER TABLE labour_payments ADD CONSTRAINT labour_payments_period_check CHECK (period_to >= period_from)');
            DB::statement('ALTER TABLE labour_advances ADD CONSTRAINT labour_advances_check CHECK (amount > 0 AND recovered_amount >= 0 AND recovered_amount <= amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_advances');
        Schema::dropIfExists('labour_payment_lines');
        Schema::dropIfExists('labour_attendance');
        Schema::dropIfExists('labour_payments');
        Schema::dropIfExists('labours');
    }
};

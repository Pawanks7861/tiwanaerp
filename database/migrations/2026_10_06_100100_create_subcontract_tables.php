<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 subcontracting (architecture H.12): work orders with BOQ-linked items (line_uid kept for
 * revision continuity), financial milestones, and measured bills. Certification of a bill posts
 * the 'subcontract' project cost; payments (Phase 7) settle it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->constrained('subcontractors')->restrictOnDelete();
            $table->string('wo_number', 40);
            $table->date('wo_date');
            $table->text('scope')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            Columns::percent($table, 'retention_percent')->default(0);
            Columns::money($table, 'advance_amount')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::percent($table, 'tax_percent')->default(0);
            Columns::percent($table, 'tds_percent')->default(0);
            Columns::money($table, 'subtotal')->default(0);
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'total_value')->default(0);
            $table->text('terms')->nullable();
            $table->string('status', 30);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'wo_number']);
            $table->index(['project_id', 'subcontractor_id']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->string('description', 500);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::rate($table, 'rate');
            Columns::money($table, 'amount');
            Columns::quantity($table, 'certified_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('boq_line_uid');
        });

        Schema::create('work_order_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('name', 200);
            $table->date('due_date')->nullable();
            Columns::percent($table, 'amount_percent')->default(0);
            $table->string('status', 20);
            $table->timestamp('achieved_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('work_order_id');
        });

        Schema::create('subcontractor_bills', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->constrained('subcontractors')->restrictOnDelete();
            $table->string('bill_number', 40);
            $table->date('bill_date');
            $table->string('subcontractor_invoice_no', 60)->nullable();
            $table->date('period_from');
            $table->date('period_to');
            Columns::percent($table, 'tax_percent')->default(0);
            Columns::percent($table, 'retention_percent')->default(0);
            Columns::percent($table, 'tds_percent')->default(0);
            Columns::money($table, 'gross_amount')->default(0);
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'retention_amount')->default(0);
            Columns::money($table, 'advance_recovery')->default(0);
            Columns::money($table, 'tds_amount')->default(0);
            Columns::money($table, 'other_deductions')->default(0);
            Columns::money($table, 'net_payable')->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->string('status', 30);
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'bill_number']);
            $table->index(['work_order_id', 'status']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('subcontractor_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontractor_bill_id')->constrained('subcontractor_bills')->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->restrictOnDelete();
            Columns::quantity($table, 'wo_qty');
            Columns::quantity($table, 'previous_qty')->default(0);
            Columns::quantity($table, 'claimed_qty')->default(0);
            Columns::quantity($table, 'certified_qty')->default(0);
            Columns::quantity($table, 'cumulative_qty')->default(0);
            Columns::rate($table, 'rate');
            Columns::money($table, 'amount')->default(0);
            $table->timestamps();

            $table->unique(['subcontractor_bill_id', 'work_order_item_id'], 'sc_bill_items_line_unique');
            $table->index('work_order_item_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_check CHECK (retention_percent >= 0 AND retention_percent <= 100 AND tds_percent >= 0 AND tds_percent <= 100 AND advance_amount >= 0 AND subtotal >= 0 AND tax_amount >= 0)');
            DB::statement('ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_check CHECK (quantity > 0 AND rate >= 0 AND amount >= 0 AND certified_qty >= 0)');
            DB::statement('ALTER TABLE work_order_milestones ADD CONSTRAINT work_order_milestones_check CHECK (amount_percent >= 0 AND amount_percent <= 100)');
            DB::statement('ALTER TABLE subcontractor_bills ADD CONSTRAINT subcontractor_bills_check CHECK (period_to >= period_from AND gross_amount >= 0 AND retention_amount >= 0 AND advance_recovery >= 0 AND tds_amount >= 0 AND other_deductions >= 0 AND net_payable >= 0)');
            DB::statement('ALTER TABLE subcontractor_bill_items ADD CONSTRAINT subcontractor_bill_items_check CHECK (claimed_qty >= 0 AND certified_qty >= 0 AND previous_qty >= 0 AND cumulative_qty = previous_qty + certified_qty)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontractor_bill_items');
        Schema::dropIfExists('subcontractor_bills');
        Schema::dropIfExists('work_order_milestones');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
    }
};

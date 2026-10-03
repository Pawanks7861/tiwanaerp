<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 CRM (architecture H.4): leads with activities, versioned quotations and the accepted
 * quotation → project conversion (converted_project_id set exactly once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('lead_number', 30);
            $table->string('name', 150);
            $table->string('company_name', 200)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 50)->nullable();
            $table->string('project_type', 50)->nullable();
            $table->string('location', 200)->nullable();
            $table->char('state_code', 2)->nullable();
            Columns::money($table, 'estimated_value')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('lost_reason', 500)->nullable();
            $table->text('notes')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'lead_number']);
            $table->index(['company_id', 'status']);
            $table->index('assigned_to');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('activity_at');
            $table->string('summary', 1000);
            $table->date('next_follow_up')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'activity_at']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('quotation_number', 30);
            $table->unsignedSmallInteger('revision')->default(0);
            $table->foreignId('parent_quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('title', 200);
            $table->string('project_name', 200);
            $table->string('project_type', 50)->nullable();
            $table->text('site_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('place_of_supply_state', 2);
            $table->string('tax_type', 10);
            Columns::money($table, 'subtotal')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'total_amount')->default(0);
            $table->text('terms')->nullable();
            $table->string('status', 20);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('converted_project_id')->nullable()->unique()->constrained('projects')->restrictOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'quotation_number', 'revision'], 'quotations_number_revision_unique');
            $table->index(['company_id', 'status']);
            $table->index('lead_id');
            $table->index('client_id');
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('description', 500);
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::rate($table, 'rate');
            Columns::percent($table, 'discount_percent')->default(0);
            Columns::money($table, 'base_amount')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::percent($table, 'cgst_rate')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::percent($table, 'sgst_rate')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::percent($table, 'igst_rate')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('quotation_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_check CHECK (estimated_value >= 0 AND status IN ('new', 'contacted', 'qualified', 'quoted', 'won', 'lost') AND (status <> 'lost' OR lost_reason IS NOT NULL))");
            DB::statement("ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_check CHECK (type IN ('call', 'visit', 'email', 'note'))");
            DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_check CHECK ((lead_id IS NOT NULL OR client_id IS NOT NULL) AND subtotal >= 0 AND discount_amount >= 0 AND taxable_amount = subtotal - discount_amount AND total_amount = taxable_amount + cgst_amount + sgst_amount + igst_amount)');
            DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_check CHECK (quantity > 0 AND rate >= 0 AND discount_percent >= 0 AND discount_percent <= 100 AND taxable_amount = base_amount - discount_amount AND amount >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};

<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('rfq_number', 30);
            $table->string('title', 200)->nullable();
            $table->date('rfq_date');
            $table->date('due_date')->nullable();
            $table->date('required_date')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'rfq_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained('material_request_items')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            $table->date('required_date')->nullable();
            $table->text('specification')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('material_request_item_id');
        });

        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('status', 30)->default('invited');
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });

        Schema::create('vendor_quotations', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('quotation_number', 50)->nullable();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->string('warranty', 255)->nullable();
            Columns::money($table, 'subtotal')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'freight_amount')->default(0);
            Columns::money($table, 'other_charges')->default(0);
            Columns::money($table, 'grand_total')->default(0);
            $table->text('remarks')->nullable();
            $table->boolean('is_selected')->default(false);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rfq_id', 'vendor_id']);
        });

        Schema::create('vendor_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_quotation_id')->constrained('vendor_quotations')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::rate($table, 'rate');
            Columns::percent($table, 'discount_percent')->default(0);
            Columns::money($table, 'base_amount')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            Columns::percent($table, 'tax_percent')->default(0);
            Columns::money($table, 'tax_amount')->default(0);
            Columns::money($table, 'amount')->default(0);
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->unique(['vendor_quotation_id', 'rfq_item_id']);
        });

        Schema::create('bid_comparisons', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('selected_vendor_quotation_id')->nullable()->constrained('vendor_quotations')->restrictOnDelete();
            $table->string('selection_basis', 30)->nullable();
            $table->text('justification')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            Columns::blame($table, false);
            $table->timestamps();

            $table->unique('rfq_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_comparisons');
        Schema::dropIfExists('vendor_quotation_items');
        Schema::dropIfExists('vendor_quotations');
        Schema::dropIfExists('rfq_vendors');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
    }
};

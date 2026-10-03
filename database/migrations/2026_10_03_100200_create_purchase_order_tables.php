<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('po_number', 30);
            $table->unsignedSmallInteger('revision_no')->default(0);
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('vendor_quotation_id')->nullable()->constrained('vendor_quotations')->restrictOnDelete();
            $table->text('direct_justification')->nullable();
            $table->date('po_date');
            $table->date('delivery_date')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->char('vendor_state_code', 2)->nullable();
            $table->char('place_of_supply_state', 2);
            $table->string('tax_type', 10);
            $table->string('payment_terms', 255)->nullable();
            $table->text('terms')->nullable();
            $table->text('remarks')->nullable();
            Columns::money($table, 'subtotal')->default(0);
            Columns::money($table, 'discount_amount')->default(0);
            Columns::money($table, 'taxable_amount')->default(0);
            Columns::money($table, 'cgst_amount')->default(0);
            Columns::money($table, 'sgst_amount')->default(0);
            Columns::money($table, 'igst_amount')->default(0);
            Columns::money($table, 'freight_amount')->default(0);
            Columns::money($table, 'other_charges')->default(0);
            Columns::money($table, 'round_off')->default(0);
            Columns::money($table, 'grand_total')->default(0);
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('cancelled_reason', 1000)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'po_number']);
            $table->index(['project_id', 'status']);
            $table->index(['vendor_id', 'po_date']);
            $table->index('rfq_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained('material_request_items')->restrictOnDelete();
            $table->foreignId('vendor_quotation_item_id')->nullable()->constrained('vendor_quotation_items')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->string('item_code', 50)->nullable();
            $table->string('description', 255);
            $table->string('hsn_sac', 10)->nullable();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
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
            Columns::quantity($table, 'received_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('material_request_item_id');
        });

        Schema::create('purchase_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->json('snapshot');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'revision_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_revisions');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};

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
        Schema::create('grns', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('grn_number', 30);
            $table->date('receipt_date');
            $table->string('vendor_invoice_no', 50)->nullable();
            $table->date('vendor_invoice_date')->nullable();
            $table->string('vendor_challan_no', 50)->nullable();
            $table->string('vehicle_no', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'grn_number']);
            $table->index('purchase_order_id');
            $table->index(['project_id', 'status']);
        });

        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('grns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'ordered_qty');
            Columns::quantity($table, 'previously_received_qty')->default(0);
            Columns::quantity($table, 'received_qty');
            Columns::quantity($table, 'rejected_qty')->default(0);
            Columns::quantity($table, 'accepted_qty');
            $table->string('rejection_reason', 500)->nullable();
            Columns::rate($table, 'rate');
            $table->timestamps();

            $table->unique(['grn_id', 'purchase_order_item_id']);
            $table->index('purchase_order_item_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE grn_items ADD CONSTRAINT grn_items_accepted_qty_check CHECK (accepted_qty = received_qty - rejected_qty AND rejected_qty >= 0 AND rejected_qty <= received_qty)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_items');
        Schema::dropIfExists('grns');
    }
};

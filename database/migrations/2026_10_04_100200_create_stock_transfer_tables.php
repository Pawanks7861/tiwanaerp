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
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('transfer_number', 30);
            $table->date('transfer_date');
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('vehicle_no', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Who cancelled the dispatch or closed the transfer short, and why.
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'transfer_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            // Captured from the source warehouse average at dispatch; receipts reuse this cost.
            Columns::rate($table, 'unit_cost')->nullable();
            Columns::money($table, 'value')->nullable();
            Columns::quantity($table, 'received_qty')->default(0);
            Columns::money($table, 'received_value')->default(0);
            // Undelivered quantity written back to the source warehouse when a transfer is closed short.
            Columns::quantity($table, 'short_closed_qty')->default(0);
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'material_id']);
        });

        Schema::create('stock_transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('remarks', 500)->nullable();
            // Client-generated token: a retried submit of the same receipt form is a no-op.
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'idempotency_key']);
        });

        Schema::create('stock_transfer_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_receipt_id')->constrained('stock_transfer_receipts')->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained('stock_transfer_items')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::money($table, 'value');
            $table->timestamps();

            $table->unique(['stock_transfer_receipt_id', 'stock_transfer_item_id'], 'str_receipt_items_unique');
            $table->index('stock_transfer_item_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_warehouses_check CHECK (from_warehouse_id <> to_warehouse_id)');
            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_qty_check CHECK (quantity > 0 AND received_qty >= 0 AND short_closed_qty >= 0 AND received_qty + short_closed_qty <= quantity)');
            DB::statement('ALTER TABLE stock_transfer_receipt_items ADD CONSTRAINT stock_transfer_receipt_items_qty_check CHECK (quantity > 0 AND value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_items');
        Schema::dropIfExists('stock_transfer_receipts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};

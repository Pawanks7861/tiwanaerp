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
        // Append-only stock ledger (architecture H.9): rows are never updated or deleted; a correction
        // is a compensating 'reversal' row pointing at the row it cancels.
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->date('txn_date');
            $table->string('txn_type', 30);
            Columns::quantity($table, 'qty_in')->default(0);
            Columns::quantity($table, 'qty_out')->default(0);
            Columns::rate($table, 'unit_cost');
            Columns::money($table, 'value');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('reverses_id')->nullable()->constrained('stock_transactions')->restrictOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['warehouse_id', 'material_id', 'txn_date']);
            $table->index(['project_id', 'material_id']);
            $table->index(['company_id', 'txn_date']);
            // Idempotency: one posting per source line and movement type, one reversal per row.
            $table->unique(['source_type', 'source_id', 'txn_type']);
            $table->unique('reverses_id');
        });

        // Derived cache of the ledger, written only by StockLedgerService (and inventory:reconcile --fix).
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            Columns::quantity($table, 'quantity')->default(0);
            Columns::rate($table, 'avg_cost')->default(0);
            Columns::money($table, 'value')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['warehouse_id', 'material_id']);
            $table->index(['company_id', 'material_id']);
        });

        // Early foundation of the project cost ledger (architecture H.14). Phase 4 writes only the
        // 'material' head from approved issues and site returns; no finance UI reads it yet.
        Schema::create('project_cost_ledger', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('cost_head', 30);
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->restrictOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->restrictOnDelete();
            $table->date('entry_date');
            Columns::money($table, 'amount');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('reverses_id')->nullable()->constrained('project_cost_ledger')->restrictOnDelete();
            $table->boolean('is_reversal')->default(false);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'cost_head', 'entry_date']);
            $table->index(['project_id', 'boq_line_uid']);
            $table->index('task_id');
            $table->unique(['source_type', 'source_id', 'cost_head', 'is_reversal'], 'project_cost_ledger_posting_unique');
            $table->unique('reverses_id');
        });

        // One open alert per warehouse + material; the row is removed once stock recovers so the
        // next dip alerts again.
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::quantity($table, 'reorder_level');
            $table->timestamp('alerted_at');

            $table->unique(['warehouse_id', 'material_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_qty_check CHECK (qty_in >= 0 AND qty_out >= 0 AND ((qty_in > 0 AND qty_out = 0) OR (qty_in = 0 AND qty_out > 0)))');
            DB::statement('ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_value_check CHECK (unit_cost >= 0 AND value >= 0)');
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_non_negative_check CHECK (quantity >= 0 AND avg_cost >= 0 AND value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('low_stock_alerts');
        Schema::dropIfExists('project_cost_ledger');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_transactions');
    }
};

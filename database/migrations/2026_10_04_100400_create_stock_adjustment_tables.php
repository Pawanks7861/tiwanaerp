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
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('adjustment_number', 30);
            $table->date('adjustment_date');
            $table->string('reason', 30);
            $table->text('remarks');
            $table->string('status', 30)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'adjustment_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // Snapshot of the book balance when the line was saved; approval refuses to post if it moved.
            Columns::quantity($table, 'system_qty');
            Columns::quantity($table, 'physical_qty');
            $table->decimal('difference', 18, 4);
            // Entered cost (opening stock, or a gain when the book average is zero); the posted cost otherwise.
            Columns::rate($table, 'unit_cost')->nullable();
            Columns::money($table, 'value')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->unique(['stock_adjustment_id', 'material_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT stock_adjustment_items_qty_check CHECK (system_qty >= 0 AND physical_qty >= 0 AND difference = physical_qty - system_qty AND difference <> 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND (value IS NULL OR value >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};

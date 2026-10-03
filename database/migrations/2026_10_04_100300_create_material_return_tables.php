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
        Schema::create('material_returns', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('return_number', 30);
            $table->string('return_type', 20);
            $table->date('return_date');
            // site_to_store: the receiving store. to_vendor: the store the goods leave from.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->foreignId('grn_id')->nullable()->constrained('grns')->restrictOnDelete();
            $table->string('reason', 500);
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'return_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('material_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_return_id')->constrained('material_returns')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('material_issue_item_id')->nullable()->constrained('material_issue_items')->restrictOnDelete();
            $table->foreignId('grn_item_id')->nullable()->constrained('grn_items')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::rate($table, 'unit_cost')->nullable();
            Columns::money($table, 'value')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index('material_issue_item_id');
            $table->index('grn_item_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE material_return_items ADD CONSTRAINT material_return_items_qty_check CHECK (quantity > 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND (value IS NULL OR value >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_return_items');
        Schema::dropIfExists('material_returns');
    }
};

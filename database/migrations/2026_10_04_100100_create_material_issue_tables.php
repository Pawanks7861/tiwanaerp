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
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('issue_number', 30);
            $table->date('issue_date');
            $table->foreignId('issued_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('subcontractor_id')->nullable()->constrained('subcontractors')->restrictOnDelete();
            $table->string('issued_to_name', 150)->nullable();
            $table->string('purpose', 500)->nullable();
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

            $table->unique(['company_id', 'issue_number']);
            $table->index(['project_id', 'status']);
            $table->index('warehouse_id');
        });

        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained('material_issues')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            // Filled from the weighted average at posting time; null while the issue is a draft.
            Columns::rate($table, 'unit_cost')->nullable();
            Columns::money($table, 'amount')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index('material_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE material_issue_items ADD CONSTRAINT material_issue_items_qty_check CHECK (quantity > 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND (amount IS NULL OR amount >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issues');
    }
};

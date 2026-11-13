<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_categories', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('scrap_types', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('scrap_category_id')->constrained('scrap_categories')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'scrap_category_id', 'code']);
        });

        Schema::create('scrap_generations', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('generation_number', 30);
            $table->date('generation_date');
            $table->string('location', 150)->nullable();
            $table->string('source_type', 30);
            $table->string('source_reference_type', 40)->nullable();
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'generation_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('scrap_generation_items', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('scrap_generation_id')->constrained('scrap_generations')->cascadeOnDelete();
            $table->foreignId('scrap_type_id')->constrained('scrap_types')->restrictOnDelete();
            $table->string('description', 300)->nullable();
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'weight')->nullable();
            $table->string('condition', 30)->nullable();
            Columns::rate($table, 'estimated_rate')->nullable();
            Columns::money($table, 'estimated_value')->nullable();
            $table->string('location', 150)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
        });

        Schema::create('scrap_ledger_entries', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('scrap_type_id')->constrained('scrap_types')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('transaction_type', 30);
            Columns::quantity($table, 'quantity_in')->default(0);
            Columns::quantity($table, 'quantity_out')->default(0);
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->string('reference_number', 60)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'scrap_type_id', 'project_id']);
            $table->unique(['reference_type', 'reference_id', 'transaction_type'], 'scrap_ledger_idempotency');
        });

        Schema::create('scrap_balances', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('scrap_type_id')->constrained('scrap_types')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity')->default(0);
            Columns::quantity($table, 'qty_generated')->default(0);
            Columns::quantity($table, 'qty_dispatched')->default(0);
            Columns::quantity($table, 'qty_adjusted_net')->default(0);
            $table->dateTime('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'project_id', 'scrap_type_id', 'unit_id'], 'scrap_balances_unique');
        });

        Schema::create('scrap_adjustments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('adjustment_number', 30);
            $table->date('adjustment_date');
            $table->string('direction', 10);
            $table->string('reason', 500);
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'adjustment_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('scrap_adjustment_items', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('scrap_adjustment_id')->constrained('scrap_adjustments')->cascadeOnDelete();
            $table->foreignId('scrap_type_id')->constrained('scrap_types')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('remarks', 500)->nullable();
        });

        if (! Schema::hasColumn('gate_pass_items', 'scrap_type_id')) {
            Schema::table('gate_pass_items', function (Blueprint $table) {
                $table->foreignId('scrap_type_id')->nullable()->after('scrap_type')->constrained('scrap_types')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gate_pass_items', 'scrap_type_id')) {
            Schema::table('gate_pass_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('scrap_type_id');
            });
        }

        Schema::dropIfExists('scrap_adjustment_items');
        Schema::dropIfExists('scrap_adjustments');
        Schema::dropIfExists('scrap_balances');
        Schema::dropIfExists('scrap_ledger_entries');
        Schema::dropIfExists('scrap_generation_items');
        Schema::dropIfExists('scrap_generations');
        Schema::dropIfExists('scrap_types');
        Schema::dropIfExists('scrap_categories');
    }
};

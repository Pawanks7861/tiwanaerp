<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_budgets', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('source', 20);
            $table->foreignId('boq_id')->nullable()->constrained('boqs')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            Columns::money($table, 'total_amount')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'version']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('project_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_budget_id')->constrained('project_budgets')->cascadeOnDelete();
            $table->string('cost_head', 20);
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->string('description', 255);
            Columns::money($table, 'amount')->default(0);
            $table->timestamps();

            $table->index(['project_budget_id', 'cost_head']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budget_lines');
        Schema::dropIfExists('project_budgets');
    }
};

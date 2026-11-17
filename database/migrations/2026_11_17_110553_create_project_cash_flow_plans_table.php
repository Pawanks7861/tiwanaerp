<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional monthly planned cash outflow baseline for Project Cost Control.
 * FAC/ETC/EAC/VAC are intentionally deferred — this is plan vs actual only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_cash_flow_plans', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120)->default('Cash Flow Plan');
            $table->boolean('is_active')->default(true);
            Columns::money($table, 'total_planned')->default(0);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'project_id']);
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('project_cash_flow_plan_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_cash_flow_plan_id')->constrained('project_cash_flow_plans')->cascadeOnDelete();
            $table->char('year_month', 7); // YYYY-MM
            Columns::money($table, 'planned_amount')->default(0);
            $table->timestamps();

            $table->unique(['project_cash_flow_plan_id', 'year_month'], 'cash_flow_plan_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cash_flow_plan_months');
        Schema::dropIfExists('project_cash_flow_plans');
    }
};

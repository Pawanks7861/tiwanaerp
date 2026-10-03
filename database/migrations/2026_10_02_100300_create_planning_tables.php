<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name', 200);
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            Columns::percent($table, 'billing_percent')->default(0);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('sort_order')->default(0);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['project_id', 'due_date']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_tasks')->restrictOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->string('wbs_code', 30);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->date('planned_start')->nullable();
            $table->date('planned_finish')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_finish')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'planned_qty')->default(0);
            Columns::quantity($table, 'completed_qty')->default(0);
            Columns::percent($table, 'progress_percent')->default(0);
            Columns::money($table, 'budget_amount')->default(0);
            Columns::money($table, 'actual_cost')->default(0);
            $table->string('status', 30)->default('not_started');
            $table->unsignedInteger('sort_order')->default(0);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'wbs_code']);
            $table->index(['project_id', 'parent_id']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('successor_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('type', 2)->default('FS');
            $table->smallInteger('lag_days')->default(0);
            $table->timestamps();

            $table->unique(['predecessor_id', 'successor_id']);
            $table->index('successor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_milestones');
    }
};

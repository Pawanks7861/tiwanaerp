<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->string('request_number', 30);
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'request_number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained('material_requests')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            Columns::quantity($table, 'quantity');
            Columns::quantity($table, 'ordered_qty')->default(0);
            Columns::quantity($table, 'received_qty')->default(0);
            $table->string('remarks', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_items');
        Schema::dropIfExists('material_requests');
    }
};

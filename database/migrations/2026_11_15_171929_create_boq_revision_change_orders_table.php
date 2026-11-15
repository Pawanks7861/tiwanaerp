<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_revision_change_orders', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('boq_number', 30);
            $table->foreignId('from_boq_id')->constrained('boqs')->restrictOnDelete();
            $table->foreignId('to_boq_id')->constrained('boqs')->restrictOnDelete();
            $table->string('change_order_number', 40);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('revision_reason', 255)->nullable();
            $table->string('status', 30)->default('draft');
            Columns::money($table, 'commercial_variation')->default(0);
            Columns::money($table, 'internal_cost_variation')->default(0);
            Columns::money($table, 'margin_variation')->default(0);
            $table->json('comparison_snapshot')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'change_order_number']);
            $table->unique(['from_boq_id', 'to_boq_id']);
            $table->index(['project_id', 'status']);
            $table->index(['boq_number', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_revision_change_orders');
    }
};

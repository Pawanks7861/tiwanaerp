<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->boolean('simple_materials_initialized')->default(false)->after('sort_order');
        });

        Schema::create('boq_item_material_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_id')->constrained('boqs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('material_name', 160);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('unit', 40);
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['boq_item_id', 'sequence']);
            $table->index(['company_id', 'boq_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_item_material_costs');

        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn('simple_materials_initialized');
        });
    }
};

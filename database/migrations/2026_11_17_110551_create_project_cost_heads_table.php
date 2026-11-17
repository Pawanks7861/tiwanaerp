<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-scoped WBS / Project Cost Head master for Cost Control
 * (Land, Building, Plant & Machinery, Electrical, IT, Others, Contingency).
 * Parallel to operational CostHead enum (material/labour/…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_cost_heads', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cost_heads');
    }
};

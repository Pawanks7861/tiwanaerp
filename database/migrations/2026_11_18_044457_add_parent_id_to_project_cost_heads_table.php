<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Future parent-child WBS support + Contingency Reserve display name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_heads', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('company_id')
                ->constrained('project_cost_heads')
                ->nullOnDelete();
            $table->index(['company_id', 'parent_id']);
        });

        DB::table('project_cost_heads')
            ->where('code', 'WBS-99')
            ->where('is_system', true)
            ->where('name', 'Contingency')
            ->update(['name' => 'Contingency Reserve']);
    }

    public function down(): void
    {
        Schema::table('project_cost_heads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};

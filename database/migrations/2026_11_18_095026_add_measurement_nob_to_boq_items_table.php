<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOB (Number of Buildings) for Simplified / Normal BOQ auto measurement.
 * Default 1 preserves historical Qty = Nos × L × W × H (NOB effectively 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->unsignedInteger('measurement_nob')
                ->default(1)
                ->after('measurement_formula');
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn('measurement_nob');
        });
    }
};

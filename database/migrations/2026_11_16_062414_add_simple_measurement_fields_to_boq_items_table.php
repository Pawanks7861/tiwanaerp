<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simplified / Normal BOQ physical measurement columns (Nos × L × W × H).
 * Nullable for historical compatibility — blank measurements leave Qty unchanged.
 * Advanced BOQ continues to use Measurement / MB; these fields are ignored there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->string('measurement_mode', 20)->nullable()->after('quantity_source');
            $table->string('measurement_formula', 40)->nullable()->after('measurement_mode');
            Columns::quantity($table, 'measurement_nos')->nullable()->after('measurement_formula');
            Columns::quantity($table, 'measurement_length')->nullable()->after('measurement_nos');
            Columns::quantity($table, 'measurement_width')->nullable()->after('measurement_length');
            Columns::quantity($table, 'measurement_height')->nullable()->after('measurement_width');
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn([
                'measurement_mode',
                'measurement_formula',
                'measurement_nos',
                'measurement_length',
                'measurement_width',
                'measurement_height',
            ]);
        });
    }
};

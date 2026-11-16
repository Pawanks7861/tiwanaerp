<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning ↔ BOQ tracking basis: Nos vs BOQ Quantity.
 * Snapshots preserve the BOQ values used when the mapping was saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_planning_maps', function (Blueprint $table) {
            $table->string('tracking_basis', 30)->nullable()->after('mapping_method');
            Columns::quantity($table, 'snapshot_boq_nos')->nullable()->after('conversion_factor');
            Columns::quantity($table, 'snapshot_boq_qty')->nullable()->after('snapshot_boq_nos');
            $table->string('snapshot_boq_unit', 40)->nullable()->after('snapshot_boq_qty');
            $table->string('snapshot_planning_unit', 40)->nullable()->after('snapshot_boq_unit');
        });
    }

    public function down(): void
    {
        Schema::table('boq_planning_maps', function (Blueprint $table) {
            $table->dropColumn([
                'tracking_basis',
                'snapshot_boq_nos',
                'snapshot_boq_qty',
                'snapshot_boq_unit',
                'snapshot_planning_unit',
            ]);
        });
    }
};

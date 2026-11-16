<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nos-basis Planning may post a different BOQ quantity (converted or actual measurement)
 * than the Planning executed quantity. Keep one ledger row; store BOQ qty separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpr_items', function (Blueprint $table) {
            Columns::quantity($table, 'boq_executed_qty')->nullable()->after('executed_qty');
            Columns::quantity($table, 'suggested_boq_qty')->nullable()->after('boq_executed_qty');
        });

        Schema::table('progress_entries', function (Blueprint $table) {
            Columns::quantity($table, 'boq_quantity')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('dpr_items', function (Blueprint $table) {
            $table->dropColumn(['boq_executed_qty', 'suggested_boq_qty']);
        });

        Schema::table('progress_entries', function (Blueprint $table) {
            $table->dropColumn(['boq_quantity']);
        });
    }
};

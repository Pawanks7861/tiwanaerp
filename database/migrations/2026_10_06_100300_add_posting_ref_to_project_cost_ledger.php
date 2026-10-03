<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 documents can be corrected after posting (attendance un-approval, bill certification
 * reversal, usage log reversal) and then posted again. The posting key therefore gains a
 * posting_ref: '' for the first posting of a source (all Phase 4 rows), 'r1', 'r2', ... for
 * re-postings after a reversal. One forward and one reversal row per posting stays guaranteed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_ledger', function (Blueprint $table) {
            $table->string('posting_ref', 20)->default('')->after('source_id');
        });

        Schema::table('project_cost_ledger', function (Blueprint $table) {
            $table->dropUnique('project_cost_ledger_posting_unique');
            $table->unique(['source_type', 'source_id', 'cost_head', 'is_reversal', 'posting_ref'], 'project_cost_ledger_posting_unique');
        });
    }

    public function down(): void
    {
        // Fails while re-posted rows (posting_ref <> '') exist: those postings would collide with
        // the original key. Roll back the Phase 6 data first.
        Schema::table('project_cost_ledger', function (Blueprint $table) {
            $table->dropUnique('project_cost_ledger_posting_unique');
            $table->unique(['source_type', 'source_id', 'cost_head', 'is_reversal'], 'project_cost_ledger_posting_unique');
        });

        Schema::table('project_cost_ledger', function (Blueprint $table) {
            $table->dropColumn('posting_ref');
        });
    }
};

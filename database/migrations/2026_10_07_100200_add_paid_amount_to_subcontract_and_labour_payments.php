<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement caches for the Phase 6 payables that Phase 7 payments allocate against. Always
 * recomputed from approved payment allocations, never incremented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontractor_bills', function (Blueprint $table) {
            Columns::money($table, 'paid_amount')->default(0)->after('net_payable');
        });

        Schema::table('labour_payments', function (Blueprint $table) {
            Columns::money($table, 'paid_amount')->default(0)->after('total_net');
        });
    }

    public function down(): void
    {
        Schema::table('labour_payments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });

        Schema::table('subcontractor_bills', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};

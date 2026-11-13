<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning activity hold qty (authoritative on the task). BOQ remains optional/historical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('project_tasks', 'hold_quantity')) {
                Columns::quantity($table, 'hold_quantity')->default(0)->after('completed_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('project_tasks', 'hold_quantity')) {
                $table->dropColumn('hold_quantity');
            }
        });
    }
};

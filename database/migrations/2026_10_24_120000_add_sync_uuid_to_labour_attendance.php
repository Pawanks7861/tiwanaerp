<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labour_attendance', function (Blueprint $table) {
            $table->uuid('sync_uuid')->nullable()->after('uuid');
            $table->index(['project_id', 'sync_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('labour_attendance', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'sync_uuid']);
            $table->dropColumn('sync_uuid');
        });
    }
};

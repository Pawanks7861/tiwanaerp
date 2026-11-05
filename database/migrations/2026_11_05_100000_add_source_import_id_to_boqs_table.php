<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boqs', function (Blueprint $table) {
            $table->foreignId('source_import_id')
                ->nullable()
                ->after('parent_boq_id')
                ->constrained('data_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_import_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->decimal('gst_rate', 8, 4)->nullable()->after('client_amount');
            $table->string('source_type', 20)->nullable()->after('simple_materials_initialized');
            $table->foreignId('source_import_id')->nullable()->after('source_type')->constrained('data_imports')->nullOnDelete();
            $table->string('source_sheet', 120)->nullable()->after('source_import_id');
            $table->unsignedInteger('source_row')->nullable()->after('source_sheet');

            $table->index(['source_import_id', 'source_row']);
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_import_id');
            $table->dropColumn(['gst_rate', 'source_type', 'source_sheet', 'source_row']);
        });
    }
};

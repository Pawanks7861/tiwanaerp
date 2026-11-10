<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->foreignId('processed_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by');
            $table->string('processing_reference', 100)->nullable()->after('processed_at');
            $table->text('processing_notes')->nullable()->after('processing_reference');
        });

        Schema::table('subcontractor_bills', function (Blueprint $table) {
            $table->foreignId('processed_by')->nullable()->after('certified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by');
            $table->string('processing_reference', 100)->nullable()->after('processed_at');
            $table->text('processing_notes')->nullable()->after('processing_reference');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
            $table->dropColumn(['processed_by', 'processed_at', 'processing_reference', 'processing_notes']);
        });

        Schema::table('subcontractor_bills', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
            $table->dropColumn(['processed_by', 'processed_at', 'processing_reference', 'processing_notes']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('source_type', 20)->default('general')->after('priority');
            $table->foreignId('boq_id')->nullable()->after('source_type')->constrained('boqs')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->after('boq_id')->constrained('boq_items')->nullOnDelete();
            $table->foreignId('drawing_revision_id')->nullable()->after('boq_item_id')->constrained('drawing_revisions')->nullOnDelete();
            $table->string('source_reason', 500)->nullable()->after('drawing_revision_id');
            $table->string('source_label_snapshot', 255)->nullable()->after('source_reason');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boq_id');
            $table->dropConstrainedForeignId('boq_item_id');
            $table->dropConstrainedForeignId('drawing_revision_id');
            $table->dropColumn(['source_type', 'source_reason', 'source_label_snapshot']);
        });
    }
};

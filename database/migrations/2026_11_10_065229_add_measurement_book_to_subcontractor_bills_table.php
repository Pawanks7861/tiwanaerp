<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontractor_bills', function (Blueprint $table) {
            $table->string('mb_number', 50)->nullable()->after('subcontractor_invoice_no');
            $table->date('mb_date')->nullable()->after('mb_number');
            $table->string('mb_reference', 100)->nullable()->after('mb_date');
            $table->text('mb_notes')->nullable()->after('mb_reference');
        });
    }

    public function down(): void
    {
        Schema::table('subcontractor_bills', function (Blueprint $table) {
            $table->dropColumn(['mb_number', 'mb_date', 'mb_reference', 'mb_notes']);
        });
    }
};

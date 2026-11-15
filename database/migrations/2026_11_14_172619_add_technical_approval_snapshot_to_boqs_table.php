<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boqs', function (Blueprint $table) {
            $table->timestamp('technical_approved_at')->nullable()->after('approved_at');
            $table->foreignId('technical_approved_by')->nullable()->after('technical_approved_at')
                ->constrained('users')->nullOnDelete();
            $table->json('technical_approval_snapshot')->nullable()->after('technical_approved_by');
            $table->string('technical_snapshot_hash', 64)->nullable()->after('technical_approval_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('boqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_approved_by');
            $table->dropColumn([
                'technical_approved_at',
                'technical_approval_snapshot',
                'technical_snapshot_hash',
            ]);
        });
    }
};

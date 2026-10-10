<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-file permission and short-lived tokens for an optional external DWG preview.
 * The raw token is never stored. No row means external preview is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_external_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->boolean('allow_external')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('external_preview_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('provider', 20);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_preview_tokens');
        Schema::dropIfExists('file_external_access');
    }
};

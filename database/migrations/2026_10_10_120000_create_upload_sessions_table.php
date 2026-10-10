<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chunk bytes live on the private disk at uploads/tmp/{uuid}/{n}, with each chunk's size and
 * SHA-256 in chunk_checksums. A 1 GiB file is about a thousand chunks; a row per chunk would
 * mostly repeat the filesystem. One file per chunk number is the uniqueness rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('declared_mime', 127)->nullable();
            $table->string('detected_mime', 127)->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('uploaded_chunks')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->json('chunk_checksums')->nullable();
            $table->string('temp_path');
            $table->string('final_path')->nullable();
            $table->string('disk', 32);
            $table->string('status', 20);
            $table->string('scan_status', 32)->default('not_configured');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};

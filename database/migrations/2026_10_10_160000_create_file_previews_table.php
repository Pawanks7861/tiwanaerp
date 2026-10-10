<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached, private preview artifacts. The original file is never replaced.
 * A changed source checksum makes the cached preview stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->char('source_checksum', 64)->nullable();
            $table->string('preview_format', 16)->nullable();
            $table->string('preview_path', 500)->nullable();
            $table->char('preview_checksum', 64)->nullable();
            $table->string('status', 20);
            $table->string('error_message', 255)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_previews');
    }
};

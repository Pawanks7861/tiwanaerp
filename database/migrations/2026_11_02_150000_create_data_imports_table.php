<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('module', 80);
            $table->string('mode', 20)->default('create');
            $table->string('status', 40);
            $table->string('original_filename');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 40)->default('private');
            $table->string('path');
            $table->json('mapping')->nullable();
            $table->json('summary')->nullable();
            $table->string('validation_path')->nullable();
            $table->string('error_report_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->text('failure_message')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'module', 'status']);
            $table->index(['company_id', 'uploaded_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};

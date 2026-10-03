<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_formats', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('document_type', 50);
            $table->string('pattern', 100);
            $table->string('reset_frequency', 20)->default('never');
            $table->string('assign_on', 20)->default('create');
            $table->timestamps();

            $table->unique(['company_id', 'document_type']);
        });

        // One row per (company, type, scope, period). Rows are locked FOR UPDATE while incrementing.
        // period_key / scope_key are NOT NULL ('' / 'global') so the unique index is effective in MySQL.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('document_type', 50);
            $table->string('scope_key', 50)->default('global');
            $table->string('period_key', 20)->default('');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'scope_key', 'period_key'], 'document_sequences_unique');
        });

        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('document_type', 50);
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('name', 150);
            Columns::money($table, 'min_amount')->nullable();
            Columns::money($table, 'max_amount')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'is_active']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('name', 100);
            $table->string('approver_type', 20);
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('project_role', 30)->nullable();
            $table->string('mode', 10)->default('any');
            $table->unsignedSmallInteger('sla_hours')->nullable();
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'level']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->morphs('approvable');
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->restrictOnDelete();
            // Steps are snapshotted at submission so later workflow edits never change an in-flight approval.
            $table->json('steps');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->string('status', 20);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Append-only history of every approval decision.
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');

            $table->index(['approval_request_id', 'level']);
        });

        // Append-only. Never updated or deleted by the application.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 30);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('url')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->morphs('attachable');
            $table->string('category', 50)->nullable();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 150);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notification_type', 100);
            $table->json('channels');
            $table->timestamps();

            $table->unique(['user_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_workflows');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('document_number_formats');
    }
};

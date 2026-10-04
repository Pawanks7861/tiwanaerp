<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: queued report exports (private files, downloadable only by their owner after the
 * report is re-authorized), one overdue alert per task and planned finish (so the daily scan
 * never repeats a notification), and indexes for the audit viewer and the notification center.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('report_key', 60);
            $table->string('format', 10);
            $table->json('filters');
            $table->string('status', 20)->default('queued');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
        });

        Schema::create('task_overdue_alerts', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->date('planned_finish');
            $table->timestamp('alerted_at');

            $table->unique(['task_id', 'planned_finish']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['company_id', 'auditable_type', 'created_at'], 'audit_logs_company_type_created_index');
            $table->index(['company_id', 'user_id', 'created_at'], 'audit_logs_company_user_created_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'company_id', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_inbox_index');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_company_user_created_index');
            $table->dropIndex('audit_logs_company_type_created_index');
        });
        Schema::dropIfExists('task_overdue_alerts');
        Schema::dropIfExists('report_exports');
    }
};

<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 drawings and documents (architecture H.16). Revision and version rows hold their file
 * (private disk, SHA-256 checksum) and are never deleted; their file columns never change after
 * insert, and an approved / superseded revision or any document version never changes at all
 * (enforced by the models and, on MySQL, by triggers). drawings.current_revision_id is the one
 * approved revision in force; documents.current_version_id the latest version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('drawing_number', 60);
            $table->string('title', 200);
            $table->string('discipline', 30);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->string('status', 20);
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'drawing_number']);
            $table->index(['project_id', 'discipline']);
        });

        Schema::create('drawing_revisions', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('drawing_id')->constrained('drawings')->restrictOnDelete();
            $table->string('revision_code', 10);
            $table->string('disk', 20);
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->string('mime', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('status', 20);
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('review_comments', 1000)->nullable();
            $table->foreignId('supersedes_revision_id')->nullable()->constrained('drawing_revisions')->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['drawing_id', 'revision_code']);
            $table->index(['drawing_id', 'status']);
        });

        Schema::table('drawings', function (Blueprint $table) {
            $table->foreign('current_revision_id')->references('id')->on('drawing_revisions')->restrictOnDelete();
        });

        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->restrictOnDelete();
            // unique(project, parent, name) must also hold for top-level folders, where parent_id is NULL.
            $table->unsignedBigInteger('parent_key')->storedAs('coalesce(parent_id, 0)');
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            Columns::blame($table, withDeletedBy: false);
            $table->timestamps();

            $table->unique(['project_id', 'parent_key', 'name']);
            $table->index('parent_id');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('document_folder_id')->nullable()->constrained('document_folders')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->string('reference_no', 100)->nullable();
            $table->string('name', 200);
            $table->string('category', 30);
            $table->string('description', 1000)->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status', 20);
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            Columns::blame($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_number']);
            $table->index(['project_id', 'document_folder_id']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('revision_label', 30)->nullable();
            $table->string('disk', 20);
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->string('mime', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version_no']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE drawings ADD CONSTRAINT drawings_check CHECK (status IN ('draft', 'approved') AND CHAR_LENGTH(TRIM(drawing_number)) > 0)");
            DB::statement("ALTER TABLE drawing_revisions ADD CONSTRAINT drawing_revisions_check CHECK (status IN ('draft', 'submitted', 'under_review', 'approved', 'rejected', 'superseded') AND CHAR_LENGTH(checksum) = 64 AND CHAR_LENGTH(TRIM(revision_code)) > 0 AND (status NOT IN ('approved', 'superseded') OR decided_at IS NOT NULL) AND (status <> 'superseded' OR superseded_at IS NOT NULL))");
            DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_check CHECK (status IN ('draft', 'active', 'archived') AND (status <> 'archived' OR archived_at IS NOT NULL))");
            DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_check CHECK (version_no >= 1 AND CHAR_LENGTH(checksum) = 64)');

            // Last line of defence for immutable evidence, below the application.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER drawing_revisions_immutable BEFORE UPDATE ON drawing_revisions FOR EACH ROW
                BEGIN
                    IF NOT (NEW.drawing_id <=> OLD.drawing_id AND NEW.revision_code <=> OLD.revision_code AND NEW.disk <=> OLD.disk
                        AND NEW.file_path <=> OLD.file_path AND NEW.file_name <=> OLD.file_name AND NEW.mime <=> OLD.mime
                        AND NEW.size_bytes <=> OLD.size_bytes AND NEW.checksum <=> OLD.checksum) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Drawing revision files are immutable.';
                    END IF;
                    IF OLD.status = 'superseded' OR (OLD.status = 'approved' AND NEW.status <> 'superseded') THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An approved drawing revision cannot be changed.';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER drawing_revisions_no_delete BEFORE DELETE ON drawing_revisions FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Drawing revisions are never deleted.';
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_versions_immutable BEFORE UPDATE ON document_versions FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document versions are immutable.';
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_versions_no_delete BEFORE DELETE ON document_versions FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document versions are never deleted.';
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');

        Schema::table('drawings', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
        });
        Schema::dropIfExists('drawing_revisions');
        Schema::dropIfExists('drawings');
    }
};

<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only progress ledger (architecture H.7). Written only by DPR approval; a correction
        // is a reversal row pointing at the row it cancels. Task caches are recomputed from it.
        Schema::create('progress_entries', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->restrictOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->restrictOnDelete();
            $table->uuid('boq_line_uid')->nullable();
            $table->date('entry_date');
            Columns::quantity($table, 'quantity');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            // Deterministic idempotency key, e.g. "dpr:12:r0:item:40" or "reversal:77".
            $table->string('posting_ref', 100)->unique();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('progress_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'entry_date']);
            $table->index(['project_id', 'boq_line_uid']);
            $table->index(['source_type', 'source_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE progress_entries ADD CONSTRAINT progress_entries_target_check CHECK (task_id IS NOT NULL OR boq_line_uid IS NOT NULL)');
            DB::statement('ALTER TABLE progress_entries ADD CONSTRAINT progress_entries_qty_check CHECK (quantity <> 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_entries');
    }
};

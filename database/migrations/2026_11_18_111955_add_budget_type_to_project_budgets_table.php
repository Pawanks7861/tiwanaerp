<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split Main Project Budget vs BOQ Estimate into independent version streams
 * on the same project_budgets table (budget_type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_budgets', function (Blueprint $table) {
            if (! Schema::hasColumn('project_budgets', 'budget_type')) {
                $table->string('budget_type', 30)->default('main_project')->after('project_id');
            }
        });

        $this->classifyExistingBudgets();
        $this->renumberVersionsPerType();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL: project_id FK is backed by the (project_id, version) unique index.
            Schema::table('project_budgets', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
            });

            if ($this->hasIndex('project_budgets_project_id_version_unique')) {
                DB::statement('ALTER TABLE project_budgets DROP INDEX project_budgets_project_id_version_unique');
            }

            if (! $this->hasIndex('project_budgets_type_version_unique')) {
                Schema::table('project_budgets', function (Blueprint $table) {
                    $table->unique(['project_id', 'budget_type', 'version'], 'project_budgets_type_version_unique');
                });
            }

            if (! $this->hasIndex('project_budgets_type_status_index')) {
                Schema::table('project_budgets', function (Blueprint $table) {
                    $table->index(['project_id', 'budget_type', 'status'], 'project_budgets_type_status_index');
                });
            }

            Schema::table('project_budgets', function (Blueprint $table) {
                $table->foreign('project_id')->references('id')->on('projects');
            });

            return;
        }

        // SQLite / others: drop old unique via Blueprint when present, then add typed unique.
        Schema::table('project_budgets', function (Blueprint $table) {
            try {
                $table->dropUnique(['project_id', 'version']);
            } catch (Throwable) {
                // Index name may differ or already dropped on fresh migrate.
            }
        });

        Schema::table('project_budgets', function (Blueprint $table) {
            $table->unique(['project_id', 'budget_type', 'version'], 'project_budgets_type_version_unique');
            $table->index(['project_id', 'budget_type', 'status'], 'project_budgets_type_status_index');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('project_budgets', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
            });
        }

        Schema::table('project_budgets', function (Blueprint $table) {
            try {
                $table->dropUnique('project_budgets_type_version_unique');
            } catch (Throwable) {
            }
            try {
                $table->dropIndex('project_budgets_type_status_index');
            } catch (Throwable) {
            }
        });

        $this->renumberVersionsPerProject();

        Schema::table('project_budgets', function (Blueprint $table) {
            $table->unique(['project_id', 'version']);
            if (Schema::hasColumn('project_budgets', 'budget_type')) {
                $table->dropColumn('budget_type');
            }
        });

        if ($driver === 'mysql') {
            Schema::table('project_budgets', function (Blueprint $table) {
                $table->foreign('project_id')->references('id')->on('projects');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        $db = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) as c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, 'project_budgets', $name]
        );

        return (int) ($row->c ?? 0) > 0;
    }

    private function classifyExistingBudgets(): void
    {
        DB::table('project_budgets')
            ->where(function ($q) {
                $q->where('source', 'boq')
                    ->orWhereNotNull('boq_id')
                    ->orWhere('materials_sync_pending', true)
                    ->orWhereNotNull('materials_synced_at');
            })
            ->update(['budget_type' => 'boq_estimate']);

        $ids = DB::table('project_budget_lines')
            ->whereIn('source_type', ['boq_material', 'boq_labour', 'boq_equipment', 'boq_other', 'boq_cost_head'])
            ->distinct()
            ->pluck('project_budget_id');

        if ($ids->isNotEmpty()) {
            DB::table('project_budgets')
                ->whereIn('id', $ids->all())
                ->update(['budget_type' => 'boq_estimate']);
        }

        DB::table('project_budgets')
            ->where(function ($q) {
                $q->whereNull('budget_type')->orWhere('budget_type', '');
            })
            ->update(['budget_type' => 'main_project']);
    }

    private function renumberVersionsPerType(): void
    {
        $rows = DB::table('project_budgets')
            ->orderBy('project_id')
            ->orderBy('budget_type')
            ->orderBy('version')
            ->orderBy('id')
            ->get(['id', 'project_id', 'budget_type']);

        $counters = [];
        foreach ($rows as $row) {
            $key = $row->project_id.'|'.$row->budget_type;
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            DB::table('project_budgets')->where('id', $row->id)->update([
                'version' => $counters[$key],
            ]);
        }
    }

    private function renumberVersionsPerProject(): void
    {
        $rows = DB::table('project_budgets')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get(['id', 'project_id']);

        $counters = [];
        foreach ($rows as $row) {
            $pid = (int) $row->project_id;
            $counters[$pid] = ($counters[$pid] ?? 0) + 1;
            DB::table('project_budgets')->where('id', $row->id)->update([
                'version' => $counters[$pid],
            ]);
        }
    }
};

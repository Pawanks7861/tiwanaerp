<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_budgets', function (Blueprint $table) {
            if (! Schema::hasColumn('project_budgets', 'materials_synced_at')) {
                $table->timestamp('materials_synced_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('project_budgets', 'materials_sync_pending')) {
                $table->boolean('materials_sync_pending')->default(false)->after('materials_synced_at');
            }
        });

        Schema::table('project_budget_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('project_budget_lines', 'source_type')) {
                $table->string('source_type', 30)->nullable()->after('boq_item_id');
            }
            if (! Schema::hasColumn('project_budget_lines', 'source_key')) {
                $table->string('source_key', 120)->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('project_budget_lines', 'material_id')) {
                $table->foreignId('material_id')->nullable()->after('source_key')->constrained('materials')->nullOnDelete();
            }
            if (! Schema::hasColumn('project_budget_lines', 'material_code')) {
                $table->string('material_code', 40)->nullable()->after('material_id');
            }
            if (! Schema::hasColumn('project_budget_lines', 'material_name')) {
                $table->string('material_name', 160)->nullable()->after('material_code');
            }
            if (! Schema::hasColumn('project_budget_lines', 'unit')) {
                $table->string('unit', 40)->nullable()->after('material_name');
            }
            if (! Schema::hasColumn('project_budget_lines', 'quantity')) {
                $table->decimal('quantity', 18, 4)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('project_budget_lines', 'rate')) {
                $table->decimal('rate', 18, 4)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('project_budget_lines', 'sources')) {
                $table->json('sources')->nullable()->after('amount');
            }
        });

        if (! $this->hasIndex('project_budget_lines', 'pbl_budget_source_key_unique')) {
            Schema::table('project_budget_lines', function (Blueprint $table) {
                $table->unique(['project_budget_id', 'source_type', 'source_key'], 'pbl_budget_source_key_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('project_budget_lines', 'pbl_budget_source_key_unique')) {
            Schema::table('project_budget_lines', function (Blueprint $table) {
                $table->dropUnique('pbl_budget_source_key_unique');
            });
        }

        Schema::table('project_budget_lines', function (Blueprint $table) {
            if (Schema::hasColumn('project_budget_lines', 'material_id')) {
                $table->dropConstrainedForeignId('material_id');
            }
            $drop = array_values(array_filter([
                Schema::hasColumn('project_budget_lines', 'source_type') ? 'source_type' : null,
                Schema::hasColumn('project_budget_lines', 'source_key') ? 'source_key' : null,
                Schema::hasColumn('project_budget_lines', 'material_code') ? 'material_code' : null,
                Schema::hasColumn('project_budget_lines', 'material_name') ? 'material_name' : null,
                Schema::hasColumn('project_budget_lines', 'unit') ? 'unit' : null,
                Schema::hasColumn('project_budget_lines', 'quantity') ? 'quantity' : null,
                Schema::hasColumn('project_budget_lines', 'rate') ? 'rate' : null,
                Schema::hasColumn('project_budget_lines', 'sources') ? 'sources' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('project_budgets', function (Blueprint $table) {
            $drop = array_values(array_filter([
                Schema::hasColumn('project_budgets', 'materials_synced_at') ? 'materials_synced_at' : null,
                Schema::hasColumn('project_budgets', 'materials_sync_pending') ? 'materials_sync_pending' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] ?? '') === $name);
    }
};

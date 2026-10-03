<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 4 tables were first created on a server whose default engine is MyISAM, which has no
 * transactions, row locks or foreign keys (MySQL accepts and silently drops the FK clauses). This
 * converts any non-InnoDB inventory table and adds back the foreign keys its create migration
 * declares. On a database created with the engine pinned to InnoDB it changes nothing.
 */
return new class extends Migration
{
    private const MIGRATIONS = [
        '2026_10_04_100000_create_stock_ledger_tables',
        '2026_10_04_100100_create_material_issue_tables',
        '2026_10_04_100200_create_stock_transfer_tables',
        '2026_10_04_100300_create_material_return_tables',
        '2026_10_04_100400_create_stock_adjustment_tables',
    ];

    private const TABLES = [
        'stock_transactions', 'stock_balances', 'project_cost_ledger', 'low_stock_alerts',
        'material_issues', 'material_issue_items',
        'stock_transfers', 'stock_transfer_items', 'stock_transfer_receipts', 'stock_transfer_receipt_items',
        'material_returns', 'material_return_items',
        'stock_adjustments', 'stock_adjustment_items',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = DB::getDatabaseName();

        $engines = collect(DB::select(
            'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database],
        ))->pluck('engine', 'name');

        foreach (self::TABLES as $table) {
            if (isset($engines[$table]) && strcasecmp((string) $engines[$table], 'InnoDB') !== 0) {
                DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
            }
        }

        $existing = collect(DB::select(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$database],
        ))->pluck('name')->flip();

        foreach ($this->declaredForeignKeys() as $name => $sql) {
            if (! $existing->has($name)) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        // Converting back to MyISAM would only reintroduce the defect.
    }

    /**
     * The exact FK statements the create migrations issue, captured without executing them.
     *
     * @return array<string, string>
     */
    private function declaredForeignKeys(): array
    {
        $statements = [];

        foreach (self::MIGRATIONS as $file) {
            $migration = require database_path("migrations/{$file}.php");

            foreach (DB::pretend(fn () => $migration->up()) as $query) {
                if (preg_match('/^alter table `[^`]+` add constraint `([^`]+)` foreign key/i', $query['query'], $m)) {
                    $statements[$m[1]] = $query['query'];
                }
            }
        }

        return $statements;
    }
};

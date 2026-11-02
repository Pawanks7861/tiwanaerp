<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Empties operational tables and restores one superadmin plus one company, then re-provisions
 * roles, masters, the current financial year and default approval workflows.
 */
class OperationalDataResetter
{
    /** @var list<string> */
    private const KEEP_TABLES = ['migrations'];

    /**
     * Tables created by this application's migrations. Never wipe other schemas that share the
     * same MySQL database (WAMP often hosts many apps in one database).
     *
     * @var list<string>
     */
    private const APP_TABLES = [
        'approval_actions', 'approval_requests', 'approval_steps', 'approval_workflows',
        'attachments', 'audit_logs', 'bid_comparisons', 'boq_drawing_revisions', 'boq_items',
        'boq_sections', 'boqs', 'cache', 'cache_locks', 'client_invoice_items', 'client_invoices',
        'clients', 'companies', 'company_settings', 'company_user', 'conversation_participants',
        'conversations', 'crew_attendance_workers', 'crew_attendances', 'device_tokens',
        'document_folders', 'document_number_formats', 'document_sequences', 'document_versions',
        'documents', 'dpr_equipment', 'dpr_items', 'dpr_labours', 'dpr_materials', 'dprs',
        'drawing_revisions', 'drawings', 'equipment', 'equipment_assignments', 'equipment_fuel_logs',
        'equipment_repairs', 'equipment_types', 'equipment_usage_logs', 'expense_categories',
        'expenses', 'external_preview_tokens', 'failed_jobs', 'file_external_access', 'file_previews',
        'financial_years', 'grn_items', 'grns', 'job_batches', 'jobs', 'labour_advances',
        'labour_attendance', 'labour_payment_lines', 'labour_payments', 'labour_trades', 'labours',
        'lead_activities', 'leads', 'low_stock_alerts', 'material_categories', 'material_issue_items',
        'material_issues', 'material_request_items', 'material_requests', 'material_return_items',
        'material_returns', 'materials', 'message_attachments', 'messages', 'ncrs',
        'notification_preferences', 'notifications', 'password_reset_tokens', 'payment_allocations',
        'payments', 'personal_access_tokens', 'petty_cash_accounts', 'petty_cash_transactions',
        'progress_entries', 'project_budget_lines', 'project_budgets', 'project_cost_ledger',
        'project_milestones', 'project_settings', 'project_tasks', 'project_users', 'projects',
        'purchase_order_items', 'purchase_order_revisions', 'purchase_orders', 'quality_checklist_items',
        'quality_checklists', 'quality_inspection_items', 'quality_inspections', 'quotation_items',
        'quotations', 'rate_analyses', 'rate_analysis_items', 'report_exports', 'retention_releases',
        'rfq_items', 'rfq_vendors', 'rfqs', 'sessions', 'site_diaries', 'site_diary_equipment',
        'site_diary_labours', 'site_diary_materials', 'site_diary_photos', 'site_diary_work_items',
        'sites', 'stock_adjustment_items', 'stock_adjustments', 'stock_balances',
        'stock_transactions', 'stock_transfer_items', 'stock_transfer_receipt_items',
        'stock_transfer_receipts', 'stock_transfers', 'subcontractor_bill_items', 'subcontractor_bills',
        'subcontractors', 'tally_connections', 'tally_cost_centre_mappings', 'tally_ledger_mappings',
        'tally_master_mappings', 'tally_sync_records', 'task_dependencies', 'task_overdue_alerts',
        'tax_rates', 'units', 'upload_sessions', 'users', 'vendor_bill_items', 'vendor_bills',
        'vendor_quotation_items', 'vendor_quotations', 'vendors', 'warehouses', 'work_order_items',
        'work_order_milestones', 'work_orders',
    ];

    public function __construct(
        private readonly CompanyProvisioner $provisioner,
        private readonly CompanyService $companies,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function reset(User $admin, Company $company): void
    {
        $userRow = $this->serialize($admin->getAttributes());
        $companyRow = $this->serialize($company->getAttributes());

        $userId = (int) $userRow['id'];
        $companyId = (int) $companyRow['id'];

        $userRow['current_company_id'] = null;
        $userRow['is_super_admin'] = 1;
        $userRow['is_active'] = 1;
        $userRow['created_by'] = null;
        $userRow['updated_by'] = null;

        $companyRow['is_active'] = 1;
        $companyRow['deleted_at'] = null;
        $companyRow['deleted_by'] = null;
        $companyRow['created_by'] = null;
        $companyRow['updated_by'] = null;

        $this->wipeOperationalTables();

        DB::table('users')->insert($this->onlyExistingColumns('users', $userRow));
        DB::table('companies')->insert($this->onlyExistingColumns('companies', $companyRow));
        DB::table('users')->where('id', $userId)->update([
            'current_company_id' => $companyId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        DB::table('companies')->where('id', $companyId)->update([
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->registrar->forgetCachedPermissions();
        $this->registrar->setPermissionsTeamId(null);

        $company = Company::query()->findOrFail($companyId);
        $admin = User::query()->findOrFail($userId);

        $this->provisioner->provision($company);
        $this->companies->addMember($company, $admin, [DefaultRoles::COMPANY_ADMIN]);
    }

    public function purgePrivateFiles(Company $company): int
    {
        $keep = array_values(array_filter(
            [$company->logo_path, $company->favicon_path],
            fn ($path) => is_string($path) && $path !== '',
        ));

        $deleted = 0;
        foreach (Storage::disk('private')->allFiles() as $path) {
            if (in_array($path, $keep, true) || str_ends_with($path, '.gitignore')) {
                continue;
            }

            Storage::disk('private')->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    private function wipeOperationalTables(): void
    {
        $tables = $this->tablesToWipe();
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            $this->dropImmutableDeleteTriggers();
        }

        try {
            Schema::withoutForeignKeyConstraints(function () use ($tables) {
                foreach ($tables as $table) {
                    try {
                        DB::table($table)->delete();
                    } catch (QueryException) {
                        continue;
                    }
                }
            });

            $remaining = $this->nonEmptyTables($tables);
            if ($remaining !== []) {
                $this->wipeByRetry($remaining);
            }
        } finally {
            if ($mysql) {
                $this->restoreImmutableDeleteTriggers();
            }
        }
    }

    private function dropImmutableDeleteTriggers(): void
    {
        foreach ([
            'drawing_revisions_immutable',
            'drawing_revisions_no_delete',
            'document_versions_immutable',
            'document_versions_no_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function restoreImmutableDeleteTriggers(): void
    {
        $this->dropImmutableDeleteTriggers();

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

    /**
     * @param  list<string>  $tables
     */
    private function wipeByRetry(array $tables): void
    {
        $this->nullNullableForeignKeys($tables);

        $remaining = $tables;
        for ($pass = 0; $pass < 30 && $remaining !== []; $pass++) {
            $blocked = [];
            foreach ($remaining as $table) {
                try {
                    DB::table($table)->delete();
                } catch (QueryException) {
                    $blocked[] = $table;
                }
            }

            if (count($blocked) === count($remaining)) {
                throw new RuntimeException('Could not empty tables: '.implode(', ', $remaining));
            }

            $remaining = $blocked;
        }
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function nonEmptyTables(array $tables): array
    {
        return array_values(array_filter(
            $tables,
            fn (string $table) => DB::table($table)->exists(),
        ));
    }

    /**
     * @param  list<string>  $tables
     */
    private function nullNullableForeignKeys(array $tables): void
    {
        foreach ($tables as $table) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                foreach ($foreignKey['columns'] as $column) {
                    if (($columns[$column]['nullable'] ?? false) !== true) {
                        continue;
                    }

                    try {
                        DB::table($table)->update([$column => null]);
                    } catch (QueryException) {
                        continue;
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tablesToWipe(): array
    {
        $allowed = array_values(array_unique(array_merge(
            self::APP_TABLES,
            array_values(config('permission.table_names', [])),
        )));

        return collect(Schema::getTableListing(schemaQualified: false))
            ->intersect($allowed)
            ->reject(fn (string $table) => in_array($table, self::KEEP_TABLES, true)
                || str_starts_with($table, 'sqlite_'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function serialize(array $attributes): array
    {
        return collect($attributes)->map(function (mixed $value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_bool($value)) {
                return $value ? 1 : 0;
            }

            if (is_array($value)) {
                return json_encode($value);
            }

            return $value;
        })->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $row): array
    {
        $columns = Schema::getColumnListing($table);

        return collect($row)->only($columns)->all();
    }
}

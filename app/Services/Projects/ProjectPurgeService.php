<?php

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\InventoryReconciler;
use App\Support\Reports\DashboardCache;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Permanently purge a Cancelled project and all project-owned transactional data.
 *
 * Inventory strategy (append-only ledger): delete project-scoped stock_transactions via the
 * query builder (Eloquent forbids delete), then rebuild affected stock_balances from the
 * remaining ledger through InventoryReconciler::fix. Shared material/warehouse masters stay.
 *
 * Finance: remove allocations to this project's payables; delete payments owned by this project
 * only when they have no remaining allocations to other projects; otherwise reassign project_id.
 *
 * MySQL drawing/document no-delete triggers are dropped for the purge window and restored
 * afterward (same pattern as OperationalDataResetter).
 *
 * Files are inventoried before the DB transaction and deleted only after a successful commit.
 */
class ProjectPurgeService
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly InventoryReconciler $inventory,
        private readonly AuditLogger $audit,
        private readonly DashboardCache $dashboards,
    ) {}

    /**
     * @return array{
     *   project: array{id:int,code:string,name:string,status:string,status_label:string},
     *   counts: array<string, int>,
     *   financial_warning: bool,
     *   tally_warning: bool,
     *   tally_exports: int,
     *   confirmation_code: string
     * }
     */
    public function preview(Project $project): array
    {
        $this->assertCancelled($project);
        $counts = $this->dependencyCounts($project);

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
            ],
            'counts' => $counts,
            'financial_warning' => $this->hasFinancialOrInventory($counts),
            'tally_warning' => ($counts['tally_exports'] ?? 0) > 0,
            'tally_exports' => $counts['tally_exports'] ?? 0,
            'confirmation_code' => $project->code,
        ];
    }

    /**
     * @return array{
     *   purge_id: string,
     *   report_path: string,
     *   file_failures: list<string>
     * }
     */
    public function purge(Project $project, User $actor, string $confirmationCode, bool $acknowledged): array
    {
        $this->assertCancelled($project);

        if (! hash_equals($project->code, trim($confirmationCode))) {
            throw ValidationException::withMessages([
                'confirmation_code' => 'Type the project code exactly to permanently delete this project.',
            ]);
        }

        if (! $acknowledged) {
            throw ValidationException::withMessages([
                'acknowledge' => 'Confirm that you understand this action permanently deletes all project data.',
            ]);
        }

        $purgeId = (string) Str::uuid();
        $snapshot = $this->preview($project);
        $files = $this->collectOwnedFiles($project);
        $meta = [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'company_id' => $project->company_id,
        ];

        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        try {
            if ($mysql) {
                $this->dropImmutableDeleteTriggers();
            }

            try {
                DB::transaction(function () use ($project, $actor, $purgeId, $snapshot) {
                    $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                    $this->assertCancelled($locked);

                    $this->deleteProjectOwnedData($locked);

                    $this->audit->record($this->tenancy->require(), 'project_permanently_deleted', null, [
                        'purge_id' => $purgeId,
                        'project_id' => $locked->id,
                        'project_code' => $locked->code,
                        'project_name' => $locked->name,
                        'deleted_by' => $actor->id,
                        'deleted_by_name' => $actor->name,
                        'counts' => $snapshot['counts'],
                    ]);

                    $locked->forceDelete();
                });
            } finally {
                if ($mysql) {
                    $this->restoreImmutableDeleteTriggers();
                }
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'project' => 'Project could not be completely deleted. No database changes were committed.',
            ]);
        }

        $this->dashboards->bump((int) $meta['company_id']);

        $fileFailures = $this->deleteFiles($files);
        $reportPath = $this->writeReport($purgeId, $meta, $actor, $snapshot, $files, $fileFailures);

        return [
            'purge_id' => $purgeId,
            'report_path' => $reportPath,
            'file_failures' => $fileFailures,
        ];
    }

    private function assertCancelled(Project $project): void
    {
        if ($project->status !== ProjectStatus::Cancelled) {
            throw ValidationException::withMessages([
                'project' => 'Only cancelled projects can be permanently deleted.',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function dependencyCounts(Project $project): array
    {
        $id = $project->id;

        return [
            'boqs' => $this->count('boqs', $id),
            'boq_items' => (int) DB::table('boq_items')->whereIn('boq_id', $this->ids('boqs', $id))->count(),
            'drawings' => $this->count('drawings', $id),
            'budget_versions' => $this->count('project_budgets', $id),
            'planning_tasks' => $this->count('project_tasks', $id),
            'dprs' => $this->count('dprs', $id),
            'material_requests' => $this->count('material_requests', $id),
            'purchase_orders' => $this->count('purchase_orders', $id),
            'grns' => $this->count('grns', $id),
            'work_orders' => $this->count('work_orders', $id),
            'vendor_bills' => $this->count('vendor_bills', $id),
            'client_invoices' => $this->count('client_invoices', $id),
            'expenses' => $this->count('expenses', $id),
            'payments' => $this->count('payments', $id),
            'stock_transactions' => $this->count('stock_transactions', $id),
            'material_issues' => $this->count('material_issues', $id),
            'documents' => $this->count('documents', $id),
            'attachments' => $this->countAttachments($project),
            'quality_inspections' => $this->count('quality_inspections', $id),
            'ncrs' => $this->count('ncrs', $id),
            'gate_passes' => $this->count('gate_passes', $id),
            'scrap_generations' => $this->count('scrap_generations', $id),
            'tally_exports' => $this->count('tally_sync_records', $id),
            'team_members' => $this->count('project_users', $id),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function hasFinancialOrInventory(array $counts): bool
    {
        foreach (['vendor_bills', 'client_invoices', 'payments', 'expenses', 'stock_transactions', 'grns', 'material_issues'] as $key) {
            if (($counts[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function count(string $table, int $projectId): int
    {
        return (int) DB::table($table)->where('project_id', $projectId)->count();
    }

    /**
     * @return list<int>
     */
    private function ids(string $table, int $projectId): array
    {
        return DB::table($table)->where('project_id', $projectId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function countAttachments(Project $project): int
    {
        $types = $this->projectMorphTypes();
        $idsByType = $this->projectMorphIds($project);

        $total = 0;
        foreach ($idsByType as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            $total += (int) DB::table('attachments')
                ->where('attachable_type', $type)
                ->whereIn('attachable_id', $ids)
                ->count();
        }

        return $total + (int) DB::table('drawing_revisions')
            ->whereIn('drawing_id', $this->ids('drawings', $project->id))
            ->whereNotNull('file_path')
            ->count()
            + (int) DB::table('document_versions')
                ->whereIn('document_id', $this->ids('documents', $project->id))
                ->whereNotNull('file_path')
                ->count()
            + (int) DB::table('site_diary_photos')
                ->whereIn('site_diary_id', $this->ids('site_diaries', $project->id))
                ->count();
    }

    /**
     * @return list<string>
     */
    private function projectMorphTypes(): array
    {
        return [
            'project', 'boq', 'purchase_order', 'grn', 'material_request', 'work_order',
            'vendor_bill', 'client_invoice', 'expense', 'payment', 'dpr', 'quality_inspection',
            'ncr', 'gate_pass', 'document', 'subcontractor_bill', 'material_issue', 'rfq',
            'retention_release', 'stock_adjustment', 'scrap_generation',
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function projectMorphIds(Project $project): array
    {
        $id = $project->id;

        return [
            'project' => [$id],
            'boq' => $this->ids('boqs', $id),
            'purchase_order' => $this->ids('purchase_orders', $id),
            'grn' => $this->ids('grns', $id),
            'material_request' => $this->ids('material_requests', $id),
            'work_order' => $this->ids('work_orders', $id),
            'vendor_bill' => $this->ids('vendor_bills', $id),
            'client_invoice' => $this->ids('client_invoices', $id),
            'expense' => $this->ids('expenses', $id),
            'payment' => $this->ids('payments', $id),
            'dpr' => $this->ids('dprs', $id),
            'quality_inspection' => $this->ids('quality_inspections', $id),
            'ncr' => $this->ids('ncrs', $id),
            'gate_pass' => $this->ids('gate_passes', $id),
            'document' => $this->ids('documents', $id),
            'subcontractor_bill' => $this->ids('subcontractor_bills', $id),
            'material_issue' => $this->ids('material_issues', $id),
            'rfq' => $this->ids('rfqs', $id),
            'retention_release' => $this->ids('retention_releases', $id),
            'stock_adjustment' => $this->ids('stock_adjustments', $id),
            'scrap_generation' => $this->ids('scrap_generations', $id),
        ];
    }

    /**
     * @return list<array{disk:string,path:string}>
     */
    private function collectOwnedFiles(Project $project): array
    {
        $files = [];
        $idsByType = $this->projectMorphIds($project);

        foreach ($idsByType as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            foreach (DB::table('attachments')->where('attachable_type', $type)->whereIn('attachable_id', $ids)->get(['disk', 'path']) as $row) {
                if ($row->path) {
                    $files[] = ['disk' => (string) $row->disk, 'path' => (string) $row->path];
                }
            }
        }

        foreach (DB::table('drawing_revisions')->whereIn('drawing_id', $this->ids('drawings', $project->id))->get(['disk', 'file_path']) as $row) {
            if ($row->file_path) {
                $files[] = ['disk' => (string) ($row->disk ?: 'local'), 'path' => (string) $row->file_path];
            }
        }

        foreach (DB::table('document_versions')->whereIn('document_id', $this->ids('documents', $project->id))->get(['disk', 'file_path']) as $row) {
            if ($row->file_path) {
                $files[] = ['disk' => (string) ($row->disk ?: 'local'), 'path' => (string) $row->file_path];
            }
        }

        foreach (DB::table('site_diary_photos')->whereIn('site_diary_id', $this->ids('site_diaries', $project->id))->get(['disk', 'path']) as $row) {
            if ($row->path ?? null) {
                $files[] = ['disk' => (string) ($row->disk ?: 'local'), 'path' => (string) $row->path];
            }
        }

        foreach (DB::table('data_imports')->where('project_id', $project->id)->get(['disk', 'path', 'validation_path', 'error_report_path']) as $row) {
            foreach (['path', 'validation_path', 'error_report_path'] as $column) {
                if (! empty($row->{$column})) {
                    $files[] = ['disk' => (string) ($row->disk ?: 'local'), 'path' => (string) $row->{$column}];
                }
            }
        }

        return collect($files)->unique(fn ($f) => $f['disk'].'::'.$f['path'])->values()->all();
    }

    private function deleteProjectOwnedData(Project $project): void
    {
        $id = $project->id;

        $this->nullSharedPointers($id);
        $this->purgeInventoryLedger($id);
        $this->purgeProjectCostLedger($id);
        $this->purgeApprovals($project);
        $this->purgeFinance($id);
        $this->purgeInventoryDocuments($id);
        $this->purgeProcurement($id);
        $this->purgeSubcontract($id);
        $this->purgeLabour($id);
        $this->purgeEquipment($id);
        $this->purgeQuality($id);
        $this->purgeGateAndScrap($id);
        $this->purgeSiteExecution($id);
        $this->purgePlanning($id);
        $this->purgeBudget($id);
        $this->purgeBoq($id);
        $this->purgeDrawings($id);
        $this->purgeDocuments($id);
        $this->purgeAttachments($project);
        $this->purgeNotifications($id);
        $this->purgeMisc($id);
        $this->purgeSitesAndWarehouses($id);
        $this->purgeTeamAndSettings($id);
    }

    private function nullSharedPointers(int $projectId): void
    {
        DB::table('equipment')->where('location_project_id', $projectId)->update(['location_project_id' => null]);
        DB::table('labours')->where('current_project_id', $projectId)->update(['current_project_id' => null]);
        DB::table('quotations')->where('converted_project_id', $projectId)->update(['converted_project_id' => null]);
        DB::table('ai_conversations')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('data_imports')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('report_exports')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('tally_sync_records')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('costing_rate_library_entries')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('material_recipe_templates')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('unit_conversions')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('steel_unit_weight_profiles')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('equipment_location_history')->where('from_project_id', $projectId)->update(['from_project_id' => null]);
        DB::table('equipment_location_history')->where('to_project_id', $projectId)->update(['to_project_id' => null]);
        DB::table('equipment_quantity_movements')->where('project_id', $projectId)->update(['project_id' => null]);
        DB::table('equipment')->where('source_grn_id', '!=', null)
            ->whereIn('source_grn_id', $this->ids('grns', $projectId))
            ->update(['source_grn_id' => null]);
        DB::table('equipment')->where('source_purchase_order_id', '!=', null)
            ->whereIn('source_purchase_order_id', $this->ids('purchase_orders', $projectId))
            ->update(['source_purchase_order_id' => null]);
    }

    private function purgeInventoryLedger(int $projectId): void
    {
        $pairs = DB::table('stock_transactions')
            ->where('project_id', $projectId)
            ->select('warehouse_id', 'material_id')
            ->distinct()
            ->get()
            ->map(fn ($r) => ['warehouse_id' => (int) $r->warehouse_id, 'material_id' => (int) $r->material_id])
            ->all();

        // Bypass append-only Eloquent guards; balances rebuilt from remaining ledger.
        DB::table('stock_transactions')->where('project_id', $projectId)->delete();

        if ($pairs !== []) {
            $this->inventory->fix($pairs);
        }
    }

    private function purgeProjectCostLedger(int $projectId): void
    {
        DB::table('project_cost_ledger')->where('project_id', $projectId)->whereNotNull('reverses_id')->update(['reverses_id' => null]);
        DB::table('project_cost_ledger')->where('project_id', $projectId)->delete();
    }

    private function purgeApprovals(Project $project): void
    {
        $idsByType = $this->projectMorphIds($project);
        $requestIds = collect();

        foreach ($idsByType as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            $requestIds = $requestIds->merge(
                DB::table('approval_requests')
                    ->where('approvable_type', $type)
                    ->whereIn('approvable_id', $ids)
                    ->pluck('id')
            );
        }

        $requestIds = $requestIds->unique()->values()->all();
        if ($requestIds === []) {
            return;
        }

        DB::table('approval_actions')->whereIn('approval_request_id', $requestIds)->delete();
        DB::table('approval_requests')->whereIn('id', $requestIds)->delete();
    }

    private function purgeFinance(int $projectId): void
    {
        $vendorBillIds = $this->ids('vendor_bills', $projectId);
        $clientInvoiceIds = $this->ids('client_invoices', $projectId);
        $expenseIds = $this->ids('expenses', $projectId);
        $subBillIds = $this->ids('subcontractor_bills', $projectId);
        $retentionIds = $this->ids('retention_releases', $projectId);
        $paymentIds = $this->ids('payments', $projectId);

        $payablePairs = [
            'vendor_bill' => $vendorBillIds,
            'client_invoice' => $clientInvoiceIds,
            'expense' => $expenseIds,
            'subcontractor_bill' => $subBillIds,
            'retention_release' => $retentionIds,
        ];

        foreach ($payablePairs as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            DB::table('payment_allocations')->where('payable_type', $type)->whereIn('payable_id', $ids)->delete();
        }

        foreach ($paymentIds as $paymentId) {
            $remaining = DB::table('payment_allocations')->where('payment_id', $paymentId)->get();
            if ($remaining->isEmpty()) {
                DB::table('payment_allocations')->where('payment_id', $paymentId)->delete();
                DB::table('attachments')->where('attachable_type', 'payment')->where('attachable_id', $paymentId)->delete();
                DB::table('payments')->where('id', $paymentId)->delete();

                continue;
            }

            $first = $remaining->first();
            $newProjectId = $this->projectIdForPayable((string) $first->payable_type, (int) $first->payable_id);
            if ($newProjectId && $newProjectId !== $projectId) {
                DB::table('payments')->where('id', $paymentId)->update(['project_id' => $newProjectId]);
            }
        }

        DB::table('retention_releases')->where('project_id', $projectId)->delete();
        DB::table('client_invoice_items')->whereIn('client_invoice_id', $clientInvoiceIds)->delete();
        DB::table('client_invoices')->where('project_id', $projectId)->delete();
        DB::table('vendor_bill_items')->whereIn('vendor_bill_id', $vendorBillIds)->delete();
        DB::table('vendor_bills')->where('project_id', $projectId)->delete();

        $pettyIds = $this->ids('petty_cash_accounts', $projectId);
        DB::table('petty_cash_transactions')->whereIn('petty_cash_account_id', $pettyIds)->delete();
        DB::table('expenses')->where('project_id', $projectId)->update(['petty_cash_account_id' => null]);
        DB::table('expenses')->where('project_id', $projectId)->delete();
        DB::table('petty_cash_accounts')->where('project_id', $projectId)->delete();
    }

    private function projectIdForPayable(string $type, int $id): ?int
    {
        $table = match ($type) {
            'vendor_bill' => 'vendor_bills',
            'client_invoice' => 'client_invoices',
            'expense' => 'expenses',
            'subcontractor_bill' => 'subcontractor_bills',
            'retention_release' => 'retention_releases',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $value = DB::table($table)->where('id', $id)->value('project_id');

        return $value !== null ? (int) $value : null;
    }

    private function purgeInventoryDocuments(int $projectId): void
    {
        $returnIds = $this->ids('material_returns', $projectId);
        DB::table('material_return_items')->whereIn('material_return_id', $returnIds)->delete();
        DB::table('material_returns')->where('project_id', $projectId)->delete();

        $issueIds = $this->ids('material_issues', $projectId);
        DB::table('equipment_fuel_logs')->whereIn('material_issue_id', $issueIds)->update([
            'material_issue_id' => null,
            'material_issue_item_id' => null,
        ]);
        DB::table('material_issue_items')->whereIn('material_issue_id', $issueIds)->delete();
        DB::table('material_issues')->where('project_id', $projectId)->delete();

        $transferIds = $this->ids('stock_transfers', $projectId);
        if (Schema::hasTable('stock_transfer_items')) {
            DB::table('stock_transfer_items')->whereIn('stock_transfer_id', $transferIds)->delete();
        }
        DB::table('stock_transfers')->where('project_id', $projectId)->delete();

        $adjIds = $this->ids('stock_adjustments', $projectId);
        if (Schema::hasTable('stock_adjustment_items')) {
            DB::table('stock_adjustment_items')->whereIn('stock_adjustment_id', $adjIds)->delete();
        }
        DB::table('stock_adjustments')->where('project_id', $projectId)->delete();
    }

    private function purgeProcurement(int $projectId): void
    {
        $grnIds = $this->ids('grns', $projectId);
        DB::table('grn_items')->whereIn('grn_id', $grnIds)->delete();
        DB::table('grns')->where('project_id', $projectId)->delete();

        $poIds = $this->ids('purchase_orders', $projectId);
        DB::table('purchase_order_revisions')->whereIn('purchase_order_id', $poIds)->delete();
        DB::table('purchase_order_items')->whereIn('purchase_order_id', $poIds)->delete();
        DB::table('purchase_orders')->where('project_id', $projectId)->delete();

        $rfqIds = $this->ids('rfqs', $projectId);
        $vqIds = DB::table('vendor_quotations')->whereIn('rfq_id', $rfqIds)->pluck('id')->all();
        if ($vqIds !== []) {
            if (Schema::hasTable('vendor_quotation_items')) {
                DB::table('vendor_quotation_items')->whereIn('vendor_quotation_id', $vqIds)->delete();
            }
            DB::table('vendor_quotations')->whereIn('id', $vqIds)->delete();
        }
        if (Schema::hasTable('bid_comparisons')) {
            DB::table('bid_comparisons')->whereIn('rfq_id', $rfqIds)->delete();
        }
        DB::table('rfq_vendors')->whereIn('rfq_id', $rfqIds)->delete();
        DB::table('rfq_items')->whereIn('rfq_id', $rfqIds)->delete();
        DB::table('rfqs')->where('project_id', $projectId)->delete();

        $mrIds = $this->ids('material_requests', $projectId);
        DB::table('material_request_items')->whereIn('material_request_id', $mrIds)->delete();
        DB::table('material_requests')->where('project_id', $projectId)->delete();
    }

    private function purgeSubcontract(int $projectId): void
    {
        $billIds = $this->ids('subcontractor_bills', $projectId);
        if (Schema::hasTable('subcontractor_bill_items')) {
            DB::table('subcontractor_bill_items')->whereIn('subcontractor_bill_id', $billIds)->delete();
        }
        DB::table('subcontractor_bills')->where('project_id', $projectId)->delete();

        $woIds = $this->ids('work_orders', $projectId);
        if (Schema::hasTable('work_order_items')) {
            DB::table('work_order_items')->whereIn('work_order_id', $woIds)->delete();
        }
        DB::table('work_orders')->where('project_id', $projectId)->delete();
    }

    private function purgeLabour(int $projectId): void
    {
        DB::table('labour_attendance')->where('project_id', $projectId)->delete();
        DB::table('labour_advances')->where('project_id', $projectId)->delete();
        DB::table('labour_payments')->where('project_id', $projectId)->delete();
    }

    private function purgeEquipment(int $projectId): void
    {
        DB::table('equipment_fuel_logs')->where('project_id', $projectId)->delete();
        DB::table('equipment_usage_logs')->where('project_id', $projectId)->delete();
        DB::table('equipment_repairs')->where('project_id', $projectId)->delete();
        DB::table('equipment_assignments')->where('project_id', $projectId)->delete();
    }

    private function purgeQuality(int $projectId): void
    {
        $ncrIds = $this->ids('ncrs', $projectId);
        if (Schema::hasTable('ncr_actions')) {
            DB::table('ncr_actions')->whereIn('ncr_id', $ncrIds)->delete();
        }
        DB::table('ncrs')->where('project_id', $projectId)->delete();

        $qiIds = $this->ids('quality_inspections', $projectId);
        DB::table('quality_inspection_items')->whereIn('quality_inspection_id', $qiIds)->delete();
        DB::table('quality_inspections')->where('project_id', $projectId)->delete();
    }

    private function purgeGateAndScrap(int $projectId): void
    {
        $gpIds = $this->ids('gate_passes', $projectId);
        DB::table('gate_pass_returns')->whereIn('gate_pass_id', $gpIds)->delete();
        DB::table('gate_pass_overdue_alerts')->whereIn('gate_pass_id', $gpIds)->delete();
        DB::table('gate_pass_items')->whereIn('gate_pass_id', $gpIds)->delete();
        DB::table('gate_passes')->where('project_id', $projectId)->delete();

        DB::table('scrap_ledger_entries')->where('project_id', $projectId)->delete();
        DB::table('scrap_balances')->where('project_id', $projectId)->delete();
        DB::table('scrap_adjustments')->where('project_id', $projectId)->delete();
        $sgIds = $this->ids('scrap_generations', $projectId);
        DB::table('scrap_generation_items')->whereIn('scrap_generation_id', $sgIds)->delete();
        DB::table('scrap_generations')->where('project_id', $projectId)->delete();
    }

    private function purgeSiteExecution(int $projectId): void
    {
        DB::table('progress_entries')->where('project_id', $projectId)->whereNotNull('reverses_id')->update(['reverses_id' => null]);
        DB::table('progress_entries')->where('project_id', $projectId)->delete();

        $dprIds = $this->ids('dprs', $projectId);
        foreach (['dpr_constraints', 'dpr_equipment', 'dpr_items', 'dpr_labours', 'dpr_materials'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('dpr_id', $dprIds)->delete();
            }
        }
        DB::table('dprs')->where('project_id', $projectId)->delete();

        $diaryIds = $this->ids('site_diaries', $projectId);
        foreach (['site_diary_equipment', 'site_diary_labours', 'site_diary_materials', 'site_diary_photos', 'site_diary_work_items'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('site_diary_id', $diaryIds)->delete();
            }
        }
        DB::table('site_diaries')->where('project_id', $projectId)->delete();
    }

    private function purgePlanning(int $projectId): void
    {
        $taskIds = $this->ids('project_tasks', $projectId);
        if ($taskIds !== []) {
            DB::table('task_dependencies')
                ->where(function ($q) use ($taskIds) {
                    $q->whereIn('predecessor_id', $taskIds)->orWhereIn('successor_id', $taskIds);
                })
                ->delete();
            DB::table('task_overdue_alerts')->whereIn('task_id', $taskIds)->delete();
        }
        DB::table('boq_planning_maps')->where('project_id', $projectId)->delete();
        DB::table('project_tasks')->where('project_id', $projectId)->update(['parent_id' => null, 'milestone_id' => null]);
        DB::table('project_tasks')->where('project_id', $projectId)->delete();
        DB::table('project_milestones')->where('project_id', $projectId)->delete();
    }

    private function purgeBudget(int $projectId): void
    {
        $budgetIds = $this->ids('project_budgets', $projectId);
        DB::table('project_budget_lines')->whereIn('project_budget_id', $budgetIds)->delete();
        DB::table('project_budgets')->where('project_id', $projectId)->delete();

        $planIds = $this->ids('project_cash_flow_plans', $projectId);
        DB::table('project_cash_flow_plan_months')->whereIn('project_cash_flow_plan_id', $planIds)->delete();
        DB::table('project_cash_flow_plans')->where('project_id', $projectId)->delete();
    }

    private function purgeBoq(int $projectId): void
    {
        $boqIds = $this->ids('boqs', $projectId);
        if ($boqIds === []) {
            DB::table('rate_analyses')->where('project_id', $projectId)->delete();

            return;
        }

        DB::table('boq_revision_change_orders')->where('project_id', $projectId)->delete();

        $variationIds = DB::table('boq_variations')->where('project_id', $projectId)->pluck('id')->all();
        if ($variationIds !== []) {
            DB::table('boq_variation_items')->whereIn('boq_variation_id', $variationIds)->delete();
            DB::table('boq_variations')->where('project_id', $projectId)->delete();
        }

        DB::table('boq_planning_maps')->where('project_id', $projectId)->delete();
        DB::table('boq_rate_comparison_quotes')->where('project_id', $projectId)->delete();
        $bbsIds = DB::table('boq_bbs')->where('project_id', $projectId)->pluck('id')->all();
        if ($bbsIds !== []) {
            if (Schema::hasTable('boq_bbs_adjustments')) {
                DB::table('boq_bbs_adjustments')->whereIn('bbs_id', $bbsIds)->delete();
            }
            if (Schema::hasTable('boq_bbs_steel_maps')) {
                DB::table('boq_bbs_steel_maps')->whereIn('bbs_id', $bbsIds)->delete();
            }
            if (Schema::hasTable('boq_bbs_lines')) {
                DB::table('boq_bbs_lines')->whereIn('bbs_id', $bbsIds)->delete();
            }
            DB::table('boq_bbs')->whereIn('id', $bbsIds)->delete();
        }
        DB::table('boq_cost_components')->where('project_id', $projectId)->delete();
        DB::table('boq_material_norms')->where('project_id', $projectId)->delete();
        DB::table('boq_item_material_costs')->where('project_id', $projectId)->delete();

        $sheetIds = DB::table('boq_measurement_sheets')->where('project_id', $projectId)->pluck('id')->all();
        if ($sheetIds !== [] && Schema::hasTable('boq_measurement_lines')) {
            DB::table('boq_measurement_lines')->whereIn('boq_measurement_sheet_id', $sheetIds)->delete();
        }
        DB::table('boq_measurement_sheets')->where('project_id', $projectId)->delete();

        if (Schema::hasTable('boq_drawing_revisions')) {
            DB::table('boq_drawing_revisions')->whereIn('boq_id', $boqIds)->delete();
        }

        DB::table('boq_items')->whereIn('boq_id', $boqIds)->delete();
        DB::table('boq_sections')->whereIn('boq_id', $boqIds)->update(['parent_id' => null]);
        DB::table('boq_sections')->whereIn('boq_id', $boqIds)->delete();
        DB::table('boqs')->where('project_id', $projectId)->update(['parent_boq_id' => null]);
        DB::table('boqs')->where('project_id', $projectId)->delete();

        $raIds = $this->ids('rate_analyses', $projectId);
        DB::table('rate_analysis_items')->whereIn('rate_analysis_id', $raIds)->delete();
        DB::table('rate_analyses')->where('project_id', $projectId)->delete();
    }

    private function purgeDrawings(int $projectId): void
    {
        $drawingIds = $this->ids('drawings', $projectId);
        DB::table('drawings')->whereIn('id', $drawingIds)->update(['current_revision_id' => null]);
        DB::table('drawing_revisions')->whereIn('drawing_id', $drawingIds)->update(['supersedes_revision_id' => null]);
        DB::table('drawing_revisions')->whereIn('drawing_id', $drawingIds)->delete();
        DB::table('drawings')->where('project_id', $projectId)->delete();
    }

    private function purgeDocuments(int $projectId): void
    {
        $docIds = $this->ids('documents', $projectId);
        DB::table('document_versions')->whereIn('document_id', $docIds)->delete();
        DB::table('documents')->where('project_id', $projectId)->delete();
        DB::table('document_folders')->where('project_id', $projectId)->update(['parent_id' => null]);
        DB::table('document_folders')->where('project_id', $projectId)->delete();
    }

    private function purgeAttachments(Project $project): void
    {
        foreach ($this->projectMorphIds($project) as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            DB::table('attachments')->where('attachable_type', $type)->whereIn('attachable_id', $ids)->delete();
        }
    }

    private function purgeNotifications(int $projectId): void
    {
        DB::table('notifications')
            ->where('data', 'like', '%"project_id":'.$projectId.'%')
            ->orWhere('data', 'like', '%"project_id": '.$projectId.'%')
            ->delete();
    }

    private function purgeMisc(int $projectId): void
    {
        DB::table('tally_cost_centre_mappings')->where('project_id', $projectId)->delete();
        DB::table('document_sequences')->where('scope_key', 'project:'.$projectId)->delete();
        if (Schema::hasTable('approval_workflows')) {
            $wfIds = DB::table('approval_workflows')->where('project_id', $projectId)->pluck('id')->all();
            if ($wfIds !== []) {
                $hasRequests = DB::table('approval_requests')->whereIn('approval_workflow_id', $wfIds)->exists();
                if (! $hasRequests) {
                    if (Schema::hasTable('approval_steps')) {
                        DB::table('approval_steps')->whereIn('approval_workflow_id', $wfIds)->delete();
                    }
                    DB::table('approval_workflows')->whereIn('id', $wfIds)->delete();
                }
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

    private function purgeSitesAndWarehouses(int $projectId): void
    {
        DB::table('warehouses')->where('project_id', $projectId)->update([
            'project_id' => null,
            'site_id' => null,
        ]);
        DB::table('sites')->where('project_id', $projectId)->delete();
    }

    private function purgeTeamAndSettings(int $projectId): void
    {
        DB::table('project_users')->where('project_id', $projectId)->delete();
        DB::table('project_settings')->where('project_id', $projectId)->delete();
    }

    /**
     * @param  list<array{disk:string,path:string}>  $files
     * @return list<string>
     */
    private function deleteFiles(array $files): array
    {
        $failures = [];
        $pathCounts = [];
        foreach ($files as $file) {
            $key = $file['disk'].'::'.$file['path'];
            $pathCounts[$key] = ($pathCounts[$key] ?? 0) + 1;
        }

        foreach ($files as $file) {
            $key = $file['disk'].'::'.$file['path'];
            $stillReferenced = (int) DB::table('attachments')->where('disk', $file['disk'])->where('path', $file['path'])->count();
            if ($stillReferenced > 0) {
                continue;
            }

            try {
                if (Storage::disk($file['disk'])->exists($file['path'])) {
                    Storage::disk($file['disk'])->delete($file['path']);
                }
            } catch (Throwable) {
                $failures[] = $key;
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{disk:string,path:string}>  $files
     * @param  list<string>  $fileFailures
     */
    /**
     * @param  array{id:int,code:string,name:string,company_id:int}  $meta
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{disk:string,path:string}>  $files
     * @param  list<string>  $fileFailures
     */
    private function writeReport(
        string $purgeId,
        array $meta,
        User $actor,
        array $snapshot,
        array $files,
        array $fileFailures,
    ): string {
        $payload = [
            'purge_id' => $purgeId,
            'project_id' => $meta['id'],
            'project_code' => $meta['code'],
            'project_name' => $meta['name'],
            'company_id' => $meta['company_id'],
            'deleted_by' => $actor->id,
            'deleted_by_name' => $actor->name,
            'deleted_at' => now()->toIso8601String(),
            'counts' => $snapshot['counts'],
            'files_scheduled' => count($files),
            'file_failures' => $fileFailures,
            'tally_warning' => $snapshot['tally_warning'],
            'financial_warning' => $snapshot['financial_warning'],
        ];

        $relative = 'purge-reports/'.$purgeId.'.json';
        Storage::disk('local')->put($relative, json_encode($payload, JSON_PRETTY_PRINT));

        return $relative;
    }
}

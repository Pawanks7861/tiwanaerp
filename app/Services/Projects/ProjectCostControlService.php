<?php

namespace App\Services\Projects;

use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\BudgetType;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Boq\ProjectBudget;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectCashFlowPlan;
use App\Queries\Reports\CommittedCostQuery;
use App\Queries\Reports\PayablesQuery;
use App\Support\Cache\ErpCache;
use App\Support\Cache\ErpCacheKey;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative Project Cost Control aggregations (WBS / cost heads).
 * Dashboards, API and exports must reuse this service — no parallel Vue formulas.
 * FAC / ETC / EAC / VAC are intentionally deferred.
 *
 * Aggregate payloads may be cache-aside cached; MySQL remains the source of truth.
 */
class ProjectCostControlService
{
    public function __construct(
        private readonly ProjectCostHeadService $heads,
        private readonly CommittedCostQuery $committed,
        private readonly PayablesQuery $payables,
        private readonly ErpCache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function projectSummary(Project $project, ?string $asOf = null): array
    {
        $companyId = (int) $project->company_id;
        $projectId = (int) $project->id;

        return $this->cache->remember(
            'project_cost_control',
            $companyId,
            ErpCacheKey::projectCostControl($companyId, $projectId, $asOf),
            ['project' => $projectId, 'as_of' => $asOf],
            fn () => $this->buildProjectSummary($project, $asOf),
            lock: true,
            flexible: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectSummary(Project $project, ?string $asOf = null): array
    {
        $companyId = (int) $project->company_id;
        $projectId = (int) $project->id;
        $heads = $this->heads->activeForCompany($project->company);
        // Cost Control ceiling = Main Project Budget only. BOQ Estimate is reference.
        $original = $this->originalByHead($companyId, [$projectId], BudgetType::MainProject)[$projectId] ?? [];
        $revised = $this->revisedByHead($companyId, [$projectId], BudgetType::MainProject)[$projectId] ?? [];
        $boqEstimate = $this->revisedByHead($companyId, [$projectId], BudgetType::BoqEstimate)[$projectId] ?? [];
        $invoiced = $this->actualInvoicedByHead($companyId, [$projectId], $asOf)[$projectId] ?? [];
        $open = $this->openCommitmentByHead($companyId, [$projectId])[$projectId] ?? [];
        $gst = $this->gstExposureByHead($companyId, [$projectId], $asOf)[$projectId] ?? [];
        $cash = $this->cashPaidByProject($companyId, [$projectId], $asOf)[$projectId] ?? Decimal::zero();
        $payable = $this->payables->outstandingByKind($companyId, [$projectId], $asOf ?? now()->toDateString());

        $rows = [];
        foreach ($heads as $head) {
            $rows[] = $this->headRow(
                $head->id,
                $head->code,
                $head->name,
                $original[$head->id] ?? Decimal::zero(),
                $revised[$head->id] ?? Decimal::zero(),
                $invoiced[$head->id] ?? Decimal::zero(),
                $open[$head->id] ?? Decimal::zero(),
                $gst[$head->id] ?? Decimal::zero(),
                $boqEstimate[$head->id] ?? Decimal::zero(),
            );
        }

        $unallocatedInvoiced = $invoiced[0] ?? Decimal::zero();
        $unallocatedOpen = $open[0] ?? Decimal::zero();
        $unallocatedGst = $gst[0] ?? Decimal::zero();
        $unallocatedBoq = $boqEstimate[0] ?? Decimal::zero();
        $unallocated = $this->headRow(
            null,
            'UNALLOC',
            'Unallocated Project Cost',
            Decimal::zero(),
            Decimal::zero(),
            $unallocatedInvoiced,
            $unallocatedOpen,
            $unallocatedGst,
            $unallocatedBoq,
        );
        if ($unallocatedBoq->isPositive()) {
            $unallocated['name'] = 'Unallocated Project Cost / Unallocated BOQ Estimate';
        }

        $totals = $this->sumRows([...$rows, $unallocated]);
        $status = $this->deriveStatus($totals, $unallocated);

        return [
            'project_id' => $projectId,
            'as_of' => $asOf,
            'date_basis' => [
                'main_project_budget' => 'Current approved Main Project Budget (Original = first approved Main baseline)',
                'boq_estimate' => 'Current approved BOQ Estimate (technical; not a commitment)',
                'budget' => 'Current approved Main Project Budget (Original = first approved Main baseline)',
                'commitment' => 'Current open approved PO / WO commitment',
                'actual_invoiced' => 'Cumulative approved / certified documents'.($asOf ? " to {$asOf}" : ''),
                'cash_paid' => 'Payment date',
            ],
            'heads' => $rows,
            'unallocated' => $unallocated,
            'totals' => $totals,
            'main_project_budget' => $totals['revised_budget'],
            'boq_estimate' => $totals['boq_estimate'],
            'boq_vs_main_budget' => $totals['boq_vs_main_budget'],
            'cash_paid' => $cash->toMoney(),
            'balance_payable' => ($payable['vendor'] ?? Decimal::zero())->plus($payable['subcontract'] ?? Decimal::zero())->toMoney(),
            'status' => $status,
            'forecast' => [
                'fac' => 'DEFERRED',
                'etc' => 'DEFERRED',
                'eac' => 'DEFERRED',
                'vac' => 'DEFERRED',
            ],
            'versions' => $this->budgetVersions($project),
            'pending_mr' => $this->pendingMrStats($companyId, [$projectId])[$projectId] ?? ['count' => 0, 'qty' => '0.0000'],
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    public function portfolio(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
        sort($projectIds);
        $asOfKey = $asOf ?? 'live';
        $projectHash = hash('sha256', implode(',', $projectIds));

        return $this->cache->remember(
            'project_cost_control',
            $companyId,
            ErpCacheKey::portfolioCostControl($companyId, $asOfKey, $projectHash),
            ['projects' => $projectIds, 'as_of' => $asOf],
            fn () => $this->buildPortfolio($companyId, $projectIds, $asOf),
            lock: true,
            flexible: true,
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private function buildPortfolio(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $projects = DB::table('projects')
            ->where('company_id', $companyId)
            ->whereIn('id', $projectIds ?: [0])
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status']);

        $company = Company::query()->findOrFail($companyId);
        $heads = $this->heads->activeForCompany($company);
        $originalAll = $this->originalByHead($companyId, $projectIds, BudgetType::MainProject);
        $revisedAll = $this->revisedByHead($companyId, $projectIds, BudgetType::MainProject);
        $boqAll = $this->revisedByHead($companyId, $projectIds, BudgetType::BoqEstimate);
        $invoicedAll = $this->actualInvoicedByHead($companyId, $projectIds, $asOf);
        $openAll = $this->openCommitmentByHead($companyId, $projectIds);
        $gstAll = $this->gstExposureByHead($companyId, $projectIds, $asOf);
        $cashAll = $this->cashPaidByProject($companyId, $projectIds, $asOf);
        $mrAll = $this->pendingMrStats($companyId, $projectIds);
        $openPoCounts = $this->openPoCounts($companyId, $projectIds);

        $projectRows = [];
        foreach ($projects as $p) {
            $pid = (int) $p->id;
            $original = Decimal::sum(array_values($originalAll[$pid] ?? []));
            $revised = Decimal::sum(array_values($revisedAll[$pid] ?? []));
            $boqEstimate = Decimal::sum(array_values($boqAll[$pid] ?? []));
            $invoiced = Decimal::sum(array_values($invoicedAll[$pid] ?? []));
            $open = Decimal::sum(array_values($openAll[$pid] ?? []));
            $assigned = $invoiced->plus($open);
            $available = $revised->minus($assigned);
            $changes = $revised->minus($original);
            $unallocated = ($invoicedAll[$pid][0] ?? Decimal::zero())->plus($openAll[$pid][0] ?? Decimal::zero());
            $totals = [
                'original_budget' => $original->toMoney(),
                'approved_changes' => $changes->toMoney(),
                'revised_budget' => $revised->toMoney(),
                'main_project_budget' => $revised->toMoney(),
                'boq_estimate' => $boqEstimate->toMoney(),
                'boq_vs_main_budget' => $boqEstimate->minus($revised)->toMoney(),
                'actual_invoiced' => $invoiced->toMoney(),
                'open_commitment' => $open->toMoney(),
                'total_assigned' => $assigned->toMoney(),
                'available_budget' => $available->toMoney(),
                'percent_assigned' => $this->percentAssigned($assigned, $revised),
                'gst_exposure' => Decimal::sum(array_values($gstAll[$pid] ?? []))->toMoney(),
                'cash_paid' => ($cashAll[$pid] ?? Decimal::zero())->toMoney(),
                'unallocated' => $unallocated->toMoney(),
            ];
            $projectRows[] = [
                'id' => $pid,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status,
                ...$totals,
                'pending_mr' => $mrAll[$pid]['count'] ?? 0,
                'open_po' => $openPoCounts[$pid] ?? 0,
                'status_indicator' => $this->deriveStatus($totals, [
                    'total_assigned' => $unallocated->toMoney(),
                    'available_budget' => $available->toMoney(),
                ]),
            ];
        }

        $headDist = [];
        foreach ($heads as $head) {
            $orig = Decimal::zero();
            $rev = Decimal::zero();
            $boq = Decimal::zero();
            $act = Decimal::zero();
            $opn = Decimal::zero();
            foreach ($projectIds as $pid) {
                $orig = $orig->plus($originalAll[$pid][$head->id] ?? Decimal::zero());
                $rev = $rev->plus($revisedAll[$pid][$head->id] ?? Decimal::zero());
                $boq = $boq->plus($boqAll[$pid][$head->id] ?? Decimal::zero());
                $act = $act->plus($invoicedAll[$pid][$head->id] ?? Decimal::zero());
                $opn = $opn->plus($openAll[$pid][$head->id] ?? Decimal::zero());
            }
            $assigned = $act->plus($opn);
            $headDist[] = [
                'id' => $head->id,
                'code' => $head->code,
                'name' => $head->name,
                'revised_budget' => $rev->toMoney(),
                'main_project_budget' => $rev->toMoney(),
                'boq_estimate' => $boq->toMoney(),
                'boq_vs_main_budget' => $boq->minus($rev)->toMoney(),
                'actual_invoiced' => $act->toMoney(),
                'open_commitment' => $opn->toMoney(),
                'available_budget' => $rev->minus($assigned)->toMoney(),
            ];
        }

        $kpis = [
            'project_count' => $projects->count(),
            'active_projects' => $projects->where('status', 'active')->count(),
            'original_budget' => Decimal::sum(array_column($projectRows, 'original_budget'))->toMoney(),
            'approved_changes' => Decimal::sum(array_column($projectRows, 'approved_changes'))->toMoney(),
            'revised_budget' => Decimal::sum(array_column($projectRows, 'revised_budget'))->toMoney(),
            'main_project_budget' => Decimal::sum(array_column($projectRows, 'main_project_budget'))->toMoney(),
            'boq_estimate' => Decimal::sum(array_column($projectRows, 'boq_estimate'))->toMoney(),
            'boq_vs_main_budget' => Decimal::sum(array_column($projectRows, 'boq_vs_main_budget'))->toMoney(),
            'actual_invoiced' => Decimal::sum(array_column($projectRows, 'actual_invoiced'))->toMoney(),
            'open_commitment' => Decimal::sum(array_column($projectRows, 'open_commitment'))->toMoney(),
            'total_assigned' => Decimal::sum(array_column($projectRows, 'total_assigned'))->toMoney(),
            'available_budget' => Decimal::sum(array_column($projectRows, 'available_budget'))->toMoney(),
            'gst_exposure' => Decimal::sum(array_column($projectRows, 'gst_exposure'))->toMoney(),
            'cash_paid' => Decimal::sum(array_column($projectRows, 'cash_paid'))->toMoney(),
            'unallocated_cost' => Decimal::sum(array_column($projectRows, 'unallocated'))->toMoney(),
        ];

        return [
            'as_of' => $asOf,
            'kpis' => $kpis,
            'projects' => $projectRows,
            'cost_heads' => $headDist,
            'vendors' => $this->vendorExposure($companyId, $projectIds, $asOf),
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    public function vendorExposure(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $ids = $projectIds ?: [0];

        $orders = DB::table('purchase_orders as po')
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $ids)
            ->whereIn('po.status', CommittedCostQuery::PO_STATES)
            ->whereNull('po.deleted_at')
            ->groupBy('po.vendor_id')
            ->selectRaw('po.vendor_id, SUM(po.subtotal) as base_orders, SUM(po.grand_total) as gross_orders')
            ->get()
            ->keyBy('vendor_id');

        $primaryWbsVendor = $this->primaryWbsForVendors($companyId, $ids);

        $wo = DB::table('work_orders as wo')
            ->where('wo.company_id', $companyId)
            ->whereIn('wo.project_id', $ids)
            ->whereIn('wo.status', CommittedCostQuery::WO_STATES)
            ->whereNull('wo.deleted_at')
            ->groupBy('wo.subcontractor_id')
            ->selectRaw('wo.subcontractor_id as party_id, SUM(wo.subtotal) as base_orders, SUM(wo.total_value) as gross_orders')
            ->get()
            ->keyBy('party_id');

        $primaryWbsSub = $this->primaryWbsForSubcontractors($companyId, $ids);

        $bills = DB::table('vendor_bills as vb')
            ->where('vb.company_id', $companyId)
            ->whereIn('vb.project_id', $ids)
            ->whereIn('vb.status', array_map(fn (VendorBillStatus $s) => $s->value, VendorBillStatus::approvedStates()))
            ->whereNull('vb.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('vb.vendor_invoice_date', '<=', $asOf))
            ->groupBy('vb.vendor_id')
            ->selectRaw('vb.vendor_id, SUM(vb.subtotal) as invoiced, SUM(vb.net_payable) as net_payable')
            ->get()
            ->keyBy('vendor_id');

        $scBills = DB::table('subcontractor_bills as sb')
            ->where('sb.company_id', $companyId)
            ->whereIn('sb.project_id', $ids)
            ->where('sb.status', SubcontractorBillStatus::Certified->value)
            ->whereNull('sb.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('sb.bill_date', '<=', $asOf))
            ->groupBy('sb.subcontractor_id')
            ->selectRaw('sb.subcontractor_id as party_id, SUM(sb.gross_amount) as invoiced, SUM(sb.net_payable) as net_payable, SUM(sb.retention_amount) as retention')
            ->get()
            ->keyBy('party_id');

        $scRetentionReleased = DB::table('retention_releases as rr')
            ->where('rr.company_id', $companyId)
            ->whereIn('rr.project_id', $ids)
            ->where('rr.releasable_type', 'subcontractor_bill')
            ->where('rr.status', 'approved')
            ->whereNull('rr.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('rr.release_date', '<=', $asOf))
            ->join('subcontractor_bills as sb', 'sb.id', '=', 'rr.releasable_id')
            ->groupBy('sb.subcontractor_id')
            ->selectRaw('sb.subcontractor_id as party_id, SUM(rr.amount) as released')
            ->pluck('released', 'party_id');

        $vendorPayments = DB::table('payments as p')
            ->where('p.company_id', $companyId)
            ->whereIn('p.project_id', $ids)
            ->where('p.status', 'approved')
            ->where('p.direction', 'payment')
            ->where('p.party_type', 'vendor')
            ->whereNull('p.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('p.payment_date', '<=', $asOf))
            ->groupBy('p.party_id')
            ->selectRaw('p.party_id as party_id, SUM(p.amount) as cash_paid')
            ->pluck('cash_paid', 'party_id');

        $scPayments = DB::table('payments as p')
            ->where('p.company_id', $companyId)
            ->whereIn('p.project_id', $ids)
            ->where('p.status', 'approved')
            ->where('p.direction', 'payment')
            ->where('p.party_type', 'subcontractor')
            ->whereNull('p.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('p.payment_date', '<=', $asOf))
            ->groupBy('p.party_id')
            ->selectRaw('p.party_id as party_id, SUM(p.amount) as cash_paid')
            ->pluck('cash_paid', 'party_id');

        $vendorIds = $orders->keys()->merge($bills->keys())->merge($vendorPayments->keys())->unique()->filter()->values();
        $names = DB::table('vendors')->whereIn('id', $vendorIds->all() ?: [0])->pluck('name', 'id');
        $headNames = DB::table('project_cost_heads')
            ->where('company_id', $companyId)
            ->pluck('name', 'id');

        $rows = [];
        foreach ($vendorIds as $vendorId) {
            $base = Num::dec($orders[$vendorId]->base_orders ?? null);
            $gross = Num::dec($orders[$vendorId]->gross_orders ?? null);
            $invoiced = Num::dec($bills[$vendorId]->invoiced ?? null);
            $open = $base->minus($invoiced);
            if ($open->isNegative()) {
                $open = Decimal::zero();
            }
            $cash = Num::dec($vendorPayments[$vendorId] ?? null);
            $balance = Num::dec($bills[$vendorId]->net_payable ?? null)->minus($cash);
            $headId = $primaryWbsVendor[(int) $vendorId] ?? null;

            $rows[] = [
                'vendor_id' => (int) $vendorId,
                'subcontractor_id' => null,
                'party_type' => 'vendor',
                'vendor_name' => $names[$vendorId] ?? 'Vendor',
                'primary_wbs' => $headId ? (string) ($headNames[$headId] ?? '') : null,
                'total_base_orders' => $base->toMoney(),
                'gross_orders' => $gross->toMoney(),
                'actual_invoiced' => $invoiced->toMoney(),
                'open_commitment' => $open->toMoney(),
                // Vendor bills have no retention column in this ERP; held retention applies to subcontract bills.
                'retention_held' => '0.00',
                'net_cash_paid' => $cash->toMoney(),
                'balance_payable' => $balance->toMoney(),
                'status' => $this->exposureStatus($balance, $open),
            ];
        }

        $scIds = $wo->keys()->merge($scBills->keys())->merge($scPayments->keys())->unique()->filter()->values();
        $scNames = DB::table('subcontractors')->whereIn('id', $scIds->all() ?: [0])->pluck('name', 'id');

        foreach ($scIds as $scId) {
            $base = Num::dec($wo[$scId]->base_orders ?? null);
            $gross = Num::dec($wo[$scId]->gross_orders ?? null);
            $invoiced = Num::dec($scBills[$scId]->invoiced ?? null);
            $open = $base->minus($invoiced);
            if ($open->isNegative()) {
                $open = Decimal::zero();
            }
            $retention = Num::dec($scBills[$scId]->retention ?? null)
                ->minus(Num::dec($scRetentionReleased[$scId] ?? null));
            if ($retention->isNegative()) {
                $retention = Decimal::zero();
            }
            $cash = Num::dec($scPayments[$scId] ?? null);
            $balance = Num::dec($scBills[$scId]->net_payable ?? null)->minus($cash);
            $headId = $primaryWbsSub[(int) $scId] ?? null;

            $rows[] = [
                'vendor_id' => null,
                'subcontractor_id' => (int) $scId,
                'party_type' => 'subcontractor',
                'vendor_name' => $scNames[$scId] ?? 'Subcontractor',
                'primary_wbs' => $headId ? (string) ($headNames[$headId] ?? '') : null,
                'total_base_orders' => $base->toMoney(),
                'gross_orders' => $gross->toMoney(),
                'actual_invoiced' => $invoiced->toMoney(),
                'open_commitment' => $open->toMoney(),
                'retention_held' => $retention->toMoney(),
                'net_cash_paid' => $cash->toMoney(),
                'balance_payable' => $balance->toMoney(),
                'status' => $this->exposureStatus($balance, $open),
            ];
        }

        usort($rows, fn ($a, $b) => Decimal::of($b['open_commitment'])->compareTo(Decimal::of($a['open_commitment'])));

        return $rows;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int> vendor_id => project_cost_head_id
     */
    private function primaryWbsForVendors(int $companyId, array $projectIds): array
    {
        $rows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $projectIds ?: [0])
            ->whereIn('po.status', CommittedCostQuery::PO_STATES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('poi.project_cost_head_id')
            ->groupBy('po.vendor_id', 'poi.project_cost_head_id')
            ->selectRaw('po.vendor_id as party_id, poi.project_cost_head_id as head_id, SUM(poi.taxable_amount) as total')
            ->orderByDesc('total')
            ->get();

        return $this->firstHeadPerParty($rows);
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int> subcontractor_id => project_cost_head_id
     */
    private function primaryWbsForSubcontractors(int $companyId, array $projectIds): array
    {
        $rows = DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->where('wo.company_id', $companyId)
            ->whereIn('wo.project_id', $projectIds ?: [0])
            ->whereIn('wo.status', CommittedCostQuery::WO_STATES)
            ->whereNull('wo.deleted_at')
            ->whereNotNull('woi.project_cost_head_id')
            ->groupBy('wo.subcontractor_id', 'woi.project_cost_head_id')
            ->selectRaw('wo.subcontractor_id as party_id, woi.project_cost_head_id as head_id, SUM(woi.amount) as total')
            ->orderByDesc('total')
            ->get();

        return $this->firstHeadPerParty($rows);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    private function firstHeadPerParty(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->party_id;
            if (! isset($out[$pid]) && $row->head_id !== null) {
                $out[$pid] = (int) $row->head_id;
            }
        }

        return $out;
    }

    private function exposureStatus(Decimal $balance, Decimal $open): string
    {
        if ($balance->isPositive()) {
            return 'Payment Due';
        }
        if ($open->isPositive()) {
            return 'Active / Open Commitment';
        }

        return 'Settled';
    }

    /**
     * @return array<string, mixed>
     */
    public function cashFlow(Project $project): array
    {
        $plan = ProjectCashFlowPlan::query()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->with('months')
            ->first();

        $planned = [];
        if ($plan) {
            foreach ($plan->months as $month) {
                $planned[$month->year_month] = Num::dec($month->planned_amount);
            }
        }

        $ym = fn (string $column): string => $this->yearMonthSql($column);

        $invoiced = DB::table('vendor_bills')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->whereIn('status', array_map(fn (VendorBillStatus $s) => $s->value, VendorBillStatus::approvedStates()))
            ->whereNull('deleted_at')
            ->selectRaw("{$ym('vendor_invoice_date')} as ym, SUM(subtotal) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $sc = DB::table('subcontractor_bills')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->where('status', SubcontractorBillStatus::Certified->value)
            ->whereNull('deleted_at')
            ->selectRaw("{$ym('bill_date')} as ym, SUM(gross_amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $expenses = DB::table('expenses')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->whereIn('status', [ExpenseStatus::Approved->value, ExpenseStatus::Paid->value])
            ->whereNull('deleted_at')
            ->selectRaw("{$ym('expense_date')} as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $paid = DB::table('payments')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->where('status', 'approved')
            ->where('direction', 'payment')
            ->whereNull('deleted_at')
            ->selectRaw("{$ym('payment_date')} as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $months = collect([$planned, $invoiced, $sc, $expenses, $paid])
            ->flatMap(fn ($c) => $c instanceof Collection ? $c->keys() : array_keys($c))
            ->unique()
            ->sort()
            ->values();

        $rows = [];
        $cumPlan = Decimal::zero();
        $cumCash = Decimal::zero();
        foreach ($months as $ym) {
            $planAmt = $planned[$ym] ?? Decimal::zero();
            $inv = Num::dec($invoiced[$ym] ?? null)->plus(Num::dec($sc[$ym] ?? null))->plus(Num::dec($expenses[$ym] ?? null));
            $cash = Num::dec($paid[$ym] ?? null);
            $cumPlan = $cumPlan->plus($planAmt);
            $cumCash = $cumCash->plus($cash);
            $variance = $plan ? $planAmt->minus($inv) : null;
            $rows[] = [
                'year_month' => $ym,
                'planned_outflow' => $plan ? $planAmt->toMoney() : null,
                'actual_invoiced' => $inv->toMoney(),
                'actual_cash_paid' => $cash->toMoney(),
                'cumulative_planned' => $plan ? $cumPlan->toMoney() : null,
                'cumulative_cash_paid' => $cumCash->toMoney(),
                'variance_planned_minus_invoiced' => $variance?->toMoney(),
            ];
        }

        $budgetTotal = Decimal::sum(array_values(
            $this->revisedByHead((int) $project->company_id, [(int) $project->id], BudgetType::MainProject)[(int) $project->id] ?? []
        ));
        $boqEstimateTotal = Decimal::sum(array_values(
            $this->revisedByHead((int) $project->company_id, [(int) $project->id], BudgetType::BoqEstimate)[(int) $project->id] ?? []
        ));
        $plannedTotal = $plan ? Num::dec($plan->total_planned) : Decimal::zero();
        $planDiff = $plan ? $plannedTotal->minus($budgetTotal) : null;

        return [
            'configured' => $plan !== null,
            'message' => $plan ? null : 'Cash Flow Plan Not Configured',
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'total_planned' => Num::dec($plan->total_planned)->toMoney(),
            ] : null,
            'budget_total' => $budgetTotal->toMoney(),
            'main_project_budget' => $budgetTotal->toMoney(),
            'boq_estimate' => $boqEstimateTotal->toMoney(),
            'plan_reconciles' => $plan ? $plannedTotal->equals($budgetTotal) : null,
            'plan_vs_budget' => $planDiff?->toMoney(),
            'months' => $rows,
            // Seed empty months for the plan editor (current FY-style 12 months from project start or now).
            'editor_months' => $this->cashFlowEditorMonths($project, $plan, $planned),
        ];
    }

    /**
     * @param  array<string, Decimal>  $planned
     * @return list<array{year_month: string, planned_amount: string}>
     */
    private function cashFlowEditorMonths(Project $project, ?ProjectCashFlowPlan $plan, array $planned): array
    {
        if ($plan !== null && $planned !== []) {
            $months = array_keys($planned);
            sort($months);

            return array_map(
                fn (string $ym) => [
                    'year_month' => $ym,
                    'planned_amount' => ($planned[$ym] ?? Decimal::zero())->toMoney(),
                ],
                $months,
            );
        }

        $start = ($project->start_date?->copy() ?? now())->startOfMonth();
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $ym = $start->copy()->addMonths($i)->format('Y-m');
            $rows[] = [
                'year_month' => $ym,
                'planned_amount' => ($planned[$ym] ?? Decimal::zero())->toMoney(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function drilldown(Project $project, ?int $projectCostHeadId): array
    {
        $companyId = (int) $project->company_id;
        $projectId = (int) $project->id;
        $headFilter = fn ($q, string $col = 'project_cost_head_id') => $projectCostHeadId === null
            ? $q->whereNull($col)
            : $q->where($col, $projectCostHeadId);

        $budgetLines = DB::table('project_budget_lines as bl')
            ->join('project_budgets as b', 'b.id', '=', 'bl.project_budget_id')
            ->where('b.company_id', $companyId)
            ->where('b.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->tap(fn ($q) => $headFilter($q, 'bl.project_cost_head_id'))
            ->orderByDesc('b.version')
            ->get(['bl.id', 'b.version', 'b.status', 'b.budget_type', 'bl.description', 'bl.amount', 'bl.project_cost_head_id']);

        $pos = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.company_id', $companyId)
            ->where('po.project_id', $projectId)
            ->whereNull('po.deleted_at')
            ->tap(fn ($q) => $headFilter($q, 'poi.project_cost_head_id'))
            ->get(['poi.id', 'po.id as document_id', 'po.po_number as document', 'po.status', 'poi.taxable_amount as amount', 'poi.description']);

        $wos = DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->where('wo.company_id', $companyId)
            ->where('wo.project_id', $projectId)
            ->whereNull('wo.deleted_at')
            ->tap(fn ($q) => $headFilter($q, 'woi.project_cost_head_id'))
            ->get(['woi.id', 'wo.id as document_id', 'wo.wo_number as document', 'wo.status', 'woi.amount', 'woi.description']);

        $bills = DB::table('vendor_bill_items as vbi')
            ->join('vendor_bills as vb', 'vb.id', '=', 'vbi.vendor_bill_id')
            ->where('vb.company_id', $companyId)
            ->where('vb.project_id', $projectId)
            ->whereNull('vb.deleted_at')
            ->tap(fn ($q) => $headFilter($q, 'vbi.project_cost_head_id'))
            ->get(['vbi.id', 'vb.id as document_id', 'vb.bill_number as document', 'vb.status', 'vbi.taxable_amount as amount', 'vbi.description']);

        $expenses = DB::table('expenses')
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->tap(fn ($q) => $headFilter($q))
            ->get(['id', 'id as document_id', 'expense_number as document', 'status', 'amount', 'description']);

        // Payments are project-scoped (no cost-head FK); shown on every head drilldown for context.
        $payments = DB::table('payments')
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->orderByDesc('payment_date')
            ->limit(100)
            ->get(['id', 'id as document_id', 'payment_number as document', 'status', 'amount', 'direction', 'party_type', 'payment_date']);

        $variations = DB::table('boq_variations')
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id',
                'id as document_id',
                'variation_number as document',
                'status',
                'cost_impact as amount',
                'title as description',
                'change_order_type',
            ]);

        return [
            'project_cost_head_id' => $projectCostHeadId,
            'budget_lines' => $budgetLines,
            'purchase_orders' => $pos,
            'work_orders' => $wos,
            'vendor_bills' => $bills,
            'expenses' => $expenses,
            'payments' => $payments,
            'variations' => $variations,
        ];
    }

    /**
     * @param  array<string, string>  $totals
     * @param  array<string, mixed>  $unallocated
     * @return array{key: string, label: string}
     */
    public function deriveStatus(array $totals, array $unallocated = []): array
    {
        $assigned = Decimal::of($totals['total_assigned'] ?? '0');
        $revised = Decimal::of($totals['revised_budget'] ?? '0');
        $available = Decimal::of($totals['available_budget'] ?? '0');
        $unalloc = Decimal::of($unallocated['total_assigned'] ?? $unallocated['unallocated'] ?? '0');

        if ($unalloc->isPositive()) {
            return ['key' => 'unallocated_cost', 'label' => 'Unallocated Cost'];
        }
        if ($revised->isPositive() && $assigned->greaterThan($revised)) {
            return [
                'key' => 'over_committed',
                'label' => 'Over Committed',
                'over_by' => $assigned->minus($revised)->toMoney(),
            ];
        }
        if ($revised->isPositive()) {
            $pct = (float) $assigned->times(100)->dividedBy($revised)->toMoney();
            if ($pct >= 95) {
                return ['key' => 'near_budget', 'label' => 'Near Budget'];
            }
            if ($pct >= 80) {
                return ['key' => 'watch', 'label' => 'Watch'];
            }
        }
        if ($available->isNegative()) {
            return ['key' => 'over_committed', 'label' => 'Over Committed'];
        }

        return ['key' => 'healthy', 'label' => 'Healthy'];
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<int, Decimal>> project => headId(0=unalloc) => amount
     */
    public function originalByHead(int $companyId, array $projectIds, BudgetType $type = BudgetType::MainProject): array
    {
        $baselines = DB::table('project_budgets')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->where('budget_type', $type->value)
            ->whereIn('status', [BudgetStatus::Approved->value, BudgetStatus::Superseded->value])
            ->whereNull('deleted_at')
            ->orderBy('project_id')
            ->orderBy('version')
            ->get(['id', 'project_id', 'version']);

        $firstIds = [];
        foreach ($baselines as $row) {
            $pid = (int) $row->project_id;
            if (! isset($firstIds[$pid])) {
                $firstIds[$pid] = (int) $row->id;
            }
        }

        return $this->sumBudgetLinesByHead($companyId, array_values($firstIds));
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<int, Decimal>>
     */
    public function revisedByHead(int $companyId, array $projectIds, BudgetType $type = BudgetType::MainProject): array
    {
        $ids = DB::table('project_budgets')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->where('budget_type', $type->value)
            ->where('status', BudgetStatus::Approved->value)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->sumBudgetLinesByHead($companyId, $ids);
    }

    /**
     * @param  list<int>  $budgetIds
     * @return array<int, array<int, Decimal>>
     */
    private function sumBudgetLinesByHead(int $companyId, array $budgetIds): array
    {
        $out = [];
        if ($budgetIds === []) {
            return $out;
        }

        DB::table('project_budget_lines as bl')
            ->join('project_budgets as b', 'b.id', '=', 'bl.project_budget_id')
            ->where('b.company_id', $companyId)
            ->whereIn('bl.project_budget_id', $budgetIds)
            ->groupBy('b.project_id', 'bl.project_cost_head_id')
            ->selectRaw('b.project_id, bl.project_cost_head_id, SUM(bl.amount) as total')
            ->get()
            ->each(function ($row) use (&$out) {
                $headId = $row->project_cost_head_id === null ? 0 : (int) $row->project_cost_head_id;
                $out[(int) $row->project_id][$headId] = Num::dec($row->total);
            });

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<int, Decimal>>
     */
    public function actualInvoicedByHead(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $out = [];
        $add = function (int $projectId, $headId, $amount) use (&$out) {
            $key = $headId === null ? 0 : (int) $headId;
            $out[$projectId][$key] = ($out[$projectId][$key] ?? Decimal::zero())->plus(Num::dec($amount));
        };

        DB::table('vendor_bill_items as vbi')
            ->join('vendor_bills as vb', 'vb.id', '=', 'vbi.vendor_bill_id')
            ->where('vb.company_id', $companyId)
            ->whereIn('vb.project_id', $projectIds ?: [0])
            ->whereIn('vb.status', array_map(fn (VendorBillStatus $s) => $s->value, VendorBillStatus::approvedStates()))
            ->whereNull('vb.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('vb.vendor_invoice_date', '<=', $asOf))
            ->groupBy('vb.project_id', 'vbi.project_cost_head_id')
            ->selectRaw('vb.project_id, vbi.project_cost_head_id, SUM(vbi.taxable_amount) as total')
            ->get()
            ->each(fn ($r) => $add((int) $r->project_id, $r->project_cost_head_id, $r->total));

        DB::table('subcontractor_bill_items as sbi')
            ->join('subcontractor_bills as sb', 'sb.id', '=', 'sbi.subcontractor_bill_id')
            ->where('sb.company_id', $companyId)
            ->whereIn('sb.project_id', $projectIds ?: [0])
            ->where('sb.status', SubcontractorBillStatus::Certified->value)
            ->whereNull('sb.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('sb.bill_date', '<=', $asOf))
            ->groupBy('sb.project_id', 'sbi.project_cost_head_id')
            ->selectRaw('sb.project_id, sbi.project_cost_head_id, SUM(sbi.amount) as total')
            ->get()
            ->each(fn ($r) => $add((int) $r->project_id, $r->project_cost_head_id, $r->total));

        DB::table('expenses')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', [ExpenseStatus::Approved->value, ExpenseStatus::Paid->value])
            ->whereNull('deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('expense_date', '<=', $asOf))
            ->groupBy('project_id', 'project_cost_head_id')
            ->selectRaw('project_id, project_cost_head_id, SUM(amount) as total')
            ->get()
            ->each(fn ($r) => $add((int) $r->project_id, $r->project_cost_head_id, $r->total));

        return $out;
    }

    /**
     * Open commitment by WBS head: approved PO open taxable + WO open value by item head.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array<int, Decimal>>
     */
    public function openCommitmentByHead(int $companyId, array $projectIds): array
    {
        $out = [];
        $add = function (int $projectId, $headId, Decimal $amount) use (&$out) {
            if (! $amount->isPositive()) {
                return;
            }
            $key = $headId === null ? 0 : (int) $headId;
            $out[$projectId][$key] = ($out[$projectId][$key] ?? Decimal::zero())->plus($amount);
        };

        $accepted = DB::table('grn_items as gi')
            ->join('grns as g', 'g.id', '=', 'gi.grn_id')
            ->where('g.company_id', $companyId)
            ->where('g.status', 'approved')
            ->whereNull('g.deleted_at')
            ->groupBy('gi.purchase_order_item_id')
            ->selectRaw('gi.purchase_order_item_id, SUM(gi.accepted_qty) as accepted');

        $invoicedPo = DB::table('vendor_bill_items as vbi')
            ->join('vendor_bills as vb', 'vb.id', '=', 'vbi.vendor_bill_id')
            ->where('vb.company_id', $companyId)
            ->whereIn('vb.status', array_map(fn (VendorBillStatus $s) => $s->value, VendorBillStatus::approvedStates()))
            ->whereNull('vb.deleted_at')
            ->whereNotNull('vbi.purchase_order_item_id')
            ->groupBy('vbi.purchase_order_item_id')
            ->selectRaw('vbi.purchase_order_item_id, SUM(vbi.taxable_amount) as invoiced');

        DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoinSub($accepted, 'r', 'r.purchase_order_item_id', '=', 'poi.id')
            ->leftJoinSub($invoicedPo, 'inv', 'inv.purchase_order_item_id', '=', 'poi.id')
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $projectIds ?: [0])
            ->whereIn('po.status', CommittedCostQuery::PO_STATES)
            ->whereNull('po.deleted_at')
            ->where('poi.quantity', '>', 0)
            ->select(
                'po.project_id',
                'poi.project_cost_head_id',
                'poi.quantity',
                'poi.taxable_amount',
            )
            ->selectRaw('COALESCE(r.accepted, 0) as accepted')
            ->selectRaw('COALESCE(inv.invoiced, 0) as invoiced')
            ->get()
            ->each(function ($line) use ($add) {
                $qty = Num::dec($line->quantity);
                $openQty = $qty->minus(Num::dec($line->accepted));
                if (! $openQty->isPositive() || $qty->isZero()) {
                    return;
                }
                $openValue = Num::dec($line->taxable_amount)->times($openQty)->dividedBy($qty);
                // Commitment remaining after invoicing against the line (floor at 0).
                $remaining = Decimal::of($openValue->toMoney())->minus(Num::dec($line->invoiced));
                // Spec: Open Commitment = Approved Commitment - Actual Invoiced Against Commitment.
                // Prefer taxable - invoiced for the line when GRN-based open is incomplete vs billed.
                $commitmentBase = Num::dec($line->taxable_amount)->minus(Num::dec($line->invoiced));
                $use = $commitmentBase->lessThan($remaining) ? $commitmentBase : $remaining;
                if ($use->isNegative()) {
                    $use = Decimal::zero();
                }
                $add((int) $line->project_id, $line->project_cost_head_id, Decimal::of($use->toMoney()));
            });

        $posted = DB::table('subcontractor_bill_items as sbi')
            ->join('subcontractor_bills as sb', 'sb.id', '=', 'sbi.subcontractor_bill_id')
            ->where('sb.company_id', $companyId)
            ->whereIn('sb.project_id', $projectIds ?: [0])
            ->where('sb.status', SubcontractorBillStatus::Certified->value)
            ->whereNull('sb.deleted_at')
            ->groupBy('sbi.work_order_item_id')
            ->selectRaw('sbi.work_order_item_id, SUM(sbi.amount) as posted')
            ->pluck('posted', 'work_order_item_id');

        DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->where('wo.company_id', $companyId)
            ->whereIn('wo.project_id', $projectIds ?: [0])
            ->whereIn('wo.status', CommittedCostQuery::WO_STATES)
            ->whereNull('wo.deleted_at')
            ->get(['wo.project_id', 'woi.id', 'woi.project_cost_head_id', 'woi.amount'])
            ->each(function ($item) use ($add, $posted) {
                $value = Num::dec($item->amount);
                $done = Num::dec($posted[$item->id] ?? null);
                $open = $value->minus($done);
                if ($open->isNegative()) {
                    $open = Decimal::zero();
                }
                $add((int) $item->project_id, $item->project_cost_head_id, Decimal::of($open->toMoney()));
            });

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<int, Decimal>>
     */
    public function gstExposureByHead(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $out = [];
        $add = function (int $projectId, $headId, $amount) use (&$out) {
            $key = $headId === null ? 0 : (int) $headId;
            $out[$projectId][$key] = ($out[$projectId][$key] ?? Decimal::zero())->plus(Num::dec($amount));
        };

        DB::table('vendor_bill_items as vbi')
            ->join('vendor_bills as vb', 'vb.id', '=', 'vbi.vendor_bill_id')
            ->where('vb.company_id', $companyId)
            ->whereIn('vb.project_id', $projectIds ?: [0])
            ->whereIn('vb.status', array_map(fn (VendorBillStatus $s) => $s->value, VendorBillStatus::approvedStates()))
            ->whereNull('vb.deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('vb.vendor_invoice_date', '<=', $asOf))
            ->groupBy('vb.project_id', 'vbi.project_cost_head_id')
            ->selectRaw('vb.project_id, vbi.project_cost_head_id, SUM(vbi.cgst_amount + vbi.sgst_amount + vbi.igst_amount) as total')
            ->get()
            ->each(fn ($r) => $add((int) $r->project_id, $r->project_cost_head_id, $r->total));

        DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('vendor_bill_items as vbi', function ($join) {
                $join->on('vbi.purchase_order_item_id', '=', 'poi.id');
            })
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $projectIds ?: [0])
            ->whereIn('po.status', CommittedCostQuery::PO_STATES)
            ->whereNull('po.deleted_at')
            ->whereNull('vbi.id')
            ->groupBy('po.project_id', 'poi.project_cost_head_id')
            ->selectRaw('po.project_id, poi.project_cost_head_id, SUM(poi.cgst_amount + poi.sgst_amount + poi.igst_amount) as total')
            ->get()
            ->each(fn ($r) => $add((int) $r->project_id, $r->project_cost_head_id, $r->total));

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, Decimal>
     */
    public function cashPaidByProject(int $companyId, array $projectIds, ?string $asOf = null): array
    {
        $out = [];
        DB::table('payments')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->where('status', 'approved')
            ->where('direction', 'payment')
            ->whereNull('deleted_at')
            ->when($asOf, fn ($q) => $q->whereDate('payment_date', '<=', $asOf))
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount) as total')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->project_id] = Num::dec($row->total);
            });

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function budgetVersions(Project $project): array
    {
        return ProjectBudget::query()
            ->where('project_id', $project->id)
            ->orderBy('budget_type')
            ->orderByDesc('version')
            ->get(['id', 'budget_type', 'version', 'status', 'source', 'total_amount', 'approved_at'])
            ->map(fn (ProjectBudget $b) => [
                'id' => $b->id,
                'budget_type' => $b->budget_type?->value ?? BudgetType::MainProject->value,
                'budget_type_label' => $b->budget_type?->label() ?? BudgetType::MainProject->label(),
                'version' => $b->version,
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'source' => $b->source?->value,
                'total_amount' => Num::dec($b->total_amount)->toMoney(),
                'approved_at' => $b->approved_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{count: int, qty: string}>
     */
    private function pendingMrStats(int $companyId, array $projectIds): array
    {
        $out = [];
        DB::table('material_requests as mr')
            ->join('material_request_items as mri', 'mri.material_request_id', '=', 'mr.id')
            ->where('mr.company_id', $companyId)
            ->whereIn('mr.project_id', $projectIds ?: [0])
            ->where('mr.status', 'approved')
            ->whereNull('mr.deleted_at')
            ->whereRaw('mri.quantity > COALESCE(mri.ordered_qty, 0)')
            ->groupBy('mr.project_id')
            ->selectRaw('mr.project_id, COUNT(DISTINCT mr.id) as cnt, SUM(mri.quantity - COALESCE(mri.ordered_qty, 0)) as qty')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->project_id] = [
                    'count' => (int) $row->cnt,
                    'qty' => Num::dec($row->qty)->toQuantity(),
                ];
            });

        return $out;
    }

    private function yearMonthSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int>
     */
    private function openPoCounts(int $companyId, array $projectIds): array
    {
        return DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', CommittedCostQuery::PO_STATES)
            ->whereNull('deleted_at')
            ->groupBy('project_id')
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->pluck('cnt', 'project_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function headRow(
        ?int $id,
        string $code,
        string $name,
        Decimal $original,
        Decimal $revised,
        Decimal $invoiced,
        Decimal $open,
        Decimal $gst,
        ?Decimal $boqEstimate = null,
    ): array {
        $boq = $boqEstimate ?? Decimal::zero();
        $changes = $revised->minus($original);
        $assigned = $invoiced->plus($open);
        $available = $revised->minus($assigned);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'original_budget' => $original->toMoney(),
            'approved_changes' => $changes->toMoney(),
            'revised_budget' => $revised->toMoney(),
            'main_project_budget' => $revised->toMoney(),
            'boq_estimate' => $boq->toMoney(),
            'boq_vs_main_budget' => $boq->minus($revised)->toMoney(),
            'actual_invoiced' => $invoiced->toMoney(),
            'open_commitment' => $open->toMoney(),
            'total_assigned' => $assigned->toMoney(),
            'available_budget' => $available->toMoney(),
            'percent_assigned' => $this->percentAssigned($assigned, $revised),
            'gst_exposure' => $gst->toMoney(),
            'total_assigned_incl_gst' => $assigned->plus($gst)->toMoney(),
            'status' => $this->deriveStatus([
                'total_assigned' => $assigned->toMoney(),
                'revised_budget' => $revised->toMoney(),
                'available_budget' => $available->toMoney(),
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function sumRows(array $rows): array
    {
        $keys = [
            'original_budget', 'approved_changes', 'revised_budget', 'main_project_budget',
            'boq_estimate', 'boq_vs_main_budget', 'actual_invoiced',
            'open_commitment', 'total_assigned', 'available_budget', 'gst_exposure',
            'total_assigned_incl_gst',
        ];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = Decimal::sum(array_column($rows, $key))->toMoney();
        }
        $totals['percent_assigned'] = $this->percentAssigned(
            Decimal::of($totals['total_assigned']),
            Decimal::of($totals['revised_budget']),
        );

        return $totals;
    }

    private function percentAssigned(Decimal $assigned, Decimal $revised): ?string
    {
        if (! $revised->isPositive()) {
            return null;
        }

        return $assigned->times(100)->dividedBy($revised)->round(2)->toString();
    }
}

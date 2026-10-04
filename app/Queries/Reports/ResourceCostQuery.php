<?php

namespace App\Queries\Reports;

use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Labour, equipment and subcontract operating reports. Money still reconciles to the cost
 * ledger: approved attendance, posted usage logs and certified bill items are exactly what was
 * posted there (the reports show the ledger total beside them).
 */
final class ResourceCostQuery
{
    /**
     * Approved attendance per labourer × project in a period.
     *
     * @param  list<int>  $projectIds
     * @param  array{subcontractor_id?: int|null, search?: string|null}  $filters
     */
    public function attendance(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('labour_attendance as a')
            ->join('labours as lb', 'lb.id', '=', 'a.labour_id')
            ->join('projects as pr', 'pr.id', '=', 'a.project_id')
            ->leftJoin('labour_trades as tr', 'tr.id', '=', 'lb.labour_trade_id')
            ->leftJoin('subcontractors as sc', 'sc.id', '=', 'lb.subcontractor_id')
            ->where('a.company_id', $companyId)
            ->whereIn('a.project_id', $projectIds ?: [0])
            ->where('a.approval_status', 'approved')
            ->where('a.attendance_date', '>=', $from)->where('a.attendance_date', '<=', Sql::eod($to))
            ->when($filters['subcontractor_id'] ?? null, fn ($q, $id) => $q->where('lb.subcontractor_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('lb.name', 'like', "%{$search}%")->orWhere('lb.code', 'like', "%{$search}%")))
            ->groupBy('a.labour_id', 'a.project_id', 'lb.code', 'lb.name', 'tr.name', 'sc.name', 'pr.code')
            ->selectRaw("a.labour_id, a.project_id, pr.code as project_code, lb.code as labour_code, lb.name as labour, tr.name as trade, sc.name as subcontractor,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as on_leave,
                SUM(a.ot_hours) as ot_hours, SUM(a.wage_amount) as wages, SUM(a.ot_amount) as ot_amount,
                SUM(a.wage_amount) + SUM(a.ot_amount) as total_cost");
    }

    /**
     * Equipment usage (posted logs), cost from the ledger, fuel and completed repairs per
     * equipment × project in a period.
     *
     * @param  list<int>  $projectIds
     * @param  array{search?: string|null}  $filters
     */
    public function equipment(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        $ids = $projectIds ?: [0];
        $eod = Sql::eod($to);

        $usage = DB::table('equipment_usage_logs as ul')
            ->join('equipment_assignments as ea', 'ea.id', '=', 'ul.equipment_assignment_id')
            ->where('ul.company_id', $companyId)->whereIn('ul.project_id', $ids)->whereNotNull('ul.posted_at')
            ->where('ul.log_date', '>=', $from)->where('ul.log_date', '<=', $eod)
            ->selectRaw('ea.equipment_id, ul.project_id, 1 as logs, ul.working_hours as working_hours, ul.idle_hours as idle_hours, 0 as cost, 0 as fuel_qty, 0 as fuel_cost, 0 as repair_cost');

        $cost = DB::table('project_cost_ledger as l')
            ->join('equipment_usage_logs as ul', function ($j) {
                $j->on('ul.id', '=', 'l.source_id')->where('l.source_type', '=', 'equipment_usage_log');
            })
            ->join('equipment_assignments as ea', 'ea.id', '=', 'ul.equipment_assignment_id')
            ->where('l.company_id', $companyId)->whereIn('l.project_id', $ids)
            ->where('l.entry_date', '>=', $from)->where('l.entry_date', '<=', $eod)
            ->selectRaw('ea.equipment_id, l.project_id, 0 as logs, 0 as working_hours, 0 as idle_hours, l.amount as cost, 0 as fuel_qty, 0 as fuel_cost, 0 as repair_cost');

        $fuel = DB::table('equipment_fuel_logs as f')
            ->where('f.company_id', $companyId)->whereIn('f.project_id', $ids)
            ->where('f.log_date', '>=', $from)->where('f.log_date', '<=', $eod)
            ->selectRaw('f.equipment_id, f.project_id, 0 as logs, 0 as working_hours, 0 as idle_hours, 0 as cost, f.fuel_added as fuel_qty, f.cost as fuel_cost, 0 as repair_cost');

        $repairs = DB::table('equipment_repairs as r')
            ->where('r.company_id', $companyId)->whereIn('r.project_id', $ids)->where('r.status', 'completed')
            ->where('r.repair_date', '>=', $from)->where('r.repair_date', '<=', $eod)
            ->selectRaw('r.equipment_id, r.project_id, 0 as logs, 0 as working_hours, 0 as idle_hours, 0 as cost, 0 as fuel_qty, 0 as fuel_cost, r.cost as repair_cost');

        $search = trim((string) ($filters['search'] ?? ''));

        return DB::query()->fromSub($usage->unionAll($cost)->unionAll($fuel)->unionAll($repairs), 'u')
            ->join('equipment as eq', 'eq.id', '=', 'u.equipment_id')
            ->join('projects as pr', 'pr.id', '=', 'u.project_id')
            ->leftJoin('equipment_types as et', 'et.id', '=', 'eq.equipment_type_id')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('eq.name', 'like', "%{$search}%")->orWhere('eq.code', 'like', "%{$search}%")))
            ->groupBy('u.equipment_id', 'u.project_id', 'eq.code', 'eq.name', 'eq.ownership', 'et.name', 'pr.code')
            ->selectRaw('u.equipment_id, u.project_id, pr.code as project_code, eq.code as equipment_code, eq.name as equipment, eq.ownership, et.name as type,
                SUM(u.logs) as logs, SUM(u.working_hours) as working_hours, SUM(u.idle_hours) as idle_hours, SUM(u.cost) as cost,
                SUM(u.fuel_qty) as fuel_qty, SUM(u.fuel_cost) as fuel_cost, SUM(u.repair_cost) as repair_cost');
    }

    /**
     * Subcontractor bills in a period (bill date) — certified values and deductions.
     *
     * @param  list<int>  $projectIds
     * @param  array{subcontractor_id?: int|null, status?: string|null, search?: string|null}  $filters
     */
    public function subcontractBills(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('subcontractor_bills as b')
            ->join('projects as pr', 'pr.id', '=', 'b.project_id')
            ->leftJoin('subcontractors as sc', 'sc.id', '=', 'b.subcontractor_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'b.work_order_id')
            ->where('b.company_id', $companyId)
            ->whereIn('b.project_id', $projectIds ?: [0])
            ->whereNull('b.deleted_at')
            ->where('b.bill_date', '>=', $from)->where('b.bill_date', '<=', Sql::eod($to))
            ->when($filters['subcontractor_id'] ?? null, fn ($q, $id) => $q->where('b.subcontractor_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('b.status', $s), fn ($q) => $q->whereIn('b.status', ['certified', 'partially_paid', 'paid']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('b.bill_number', 'like', "%{$search}%")->orWhere('sc.name', 'like', "%{$search}%")))
            ->select('b.id', 'b.project_id', 'pr.code as project_code', 'sc.name as subcontractor', 'wo.wo_number', 'b.bill_number', 'b.bill_date', 'b.status',
                'b.gross_amount', 'b.tax_amount', 'b.retention_amount', 'b.advance_recovery', 'b.tds_amount', 'b.other_deductions', 'b.net_payable', 'b.paid_amount');
    }

    /**
     * Work orders: value, certified to date, open (committed) per WO.
     *
     * @param  list<int>  $projectIds
     */
    public function workOrders(int $companyId, array $projectIds, ?int $subcontractorId = null): Builder
    {
        return DB::table('work_orders as wo')
            ->join('projects as pr', 'pr.id', '=', 'wo.project_id')
            ->leftJoin('subcontractors as sc', 'sc.id', '=', 'wo.subcontractor_id')
            ->where('wo.company_id', $companyId)
            ->whereIn('wo.project_id', $projectIds ?: [0])
            ->whereNull('wo.deleted_at')
            ->whereNotIn('wo.status', ['draft', 'rejected'])
            ->when($subcontractorId, fn ($q, $id) => $q->where('wo.subcontractor_id', $id))
            ->select('wo.id', 'wo.project_id', 'pr.code as project_code', 'wo.wo_number', 'sc.name as subcontractor', 'wo.status', 'wo.subtotal', 'wo.total_value');
    }
}

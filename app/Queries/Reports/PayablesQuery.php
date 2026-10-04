<?php

namespace App\Queries\Reports;

use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payables as of a date, one uniform row shape for the three kinds:
 *  - vendor: approved vendor bills (approved, partially paid, paid); due = net payable (after
 *    TDS); aging from the due date, else the vendor invoice date;
 *  - subcontract: certified subcontractor bills; due = net payable + released retention;
 *  - labour: payment batches approved for Finance (approved, or paid through Finance). Batches
 *    marked paid directly in Phase 6 (paid_amount 0) were settled outside Finance and are not
 *    payables. Attendance is never a payable by itself.
 * settled = Σ allocations of approved payments dated on or before the as-of date.
 */
final class PayablesQuery
{
    public const KINDS = ['vendor' => 'Vendor', 'subcontract' => 'Subcontractor', 'labour' => 'Labour'];

    /**
     * @param  list<int>  $projectIds
     * @param  array{kind?: string|null, vendor_id?: int|null, subcontractor_id?: int|null, status?: string|null, search?: string|null}  $filters
     */
    public function base(int $companyId, array $projectIds, string $asOf, array $filters = []): Builder
    {
        $kind = $filters['kind'] ?? null;
        $parts = array_filter([
            (! $kind || $kind === 'vendor') && empty($filters['subcontractor_id']) ? $this->vendor($companyId, $projectIds, $asOf, $filters) : null,
            (! $kind || $kind === 'subcontract') && empty($filters['vendor_id']) ? $this->subcontract($companyId, $projectIds, $asOf, $filters) : null,
            (! $kind || $kind === 'labour') && empty($filters['vendor_id']) && empty($filters['subcontractor_id']) ? $this->labour($companyId, $projectIds, $asOf) : null,
        ]);
        if ($parts === []) {
            $parts = [$this->vendor($companyId, [0], $asOf, [])];
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        return DB::query()->fromSub($union, 'x')
            ->when(($filters['status'] ?? null) === 'outstanding', fn ($q) => $q->where('outstanding', '>', 0))
            ->when(($filters['status'] ?? null) === 'settled', fn ($q) => $q->where('outstanding', '<=', 0))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('document', 'like', "%{$search}%")->orWhere('party', 'like', "%{$search}%")));
    }

    /**
     * @return array{count: int, gross: Decimal, deductions: Decimal, due: Decimal, settled: Decimal, outstanding: Decimal, b0_30: Decimal, b31_60: Decimal, b61_90: Decimal, b90_plus: Decimal}
     */
    public function totals(Builder $base): array
    {
        $row = DB::query()->fromSub($base, 't')
            ->selectRaw('COUNT(*) as cnt, SUM(gross) as gross, SUM(deductions) as deductions, SUM(due) as due, SUM(settled) as settled,
                SUM(outstanding) as outstanding, '.Settlements::bucketSelect())
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'gross' => Num::dec($row->gross ?? null),
            'deductions' => Num::dec($row->deductions ?? null),
            'due' => Num::dec($row->due ?? null),
            'settled' => Num::dec($row->settled ?? null),
            'outstanding' => Num::dec($row->outstanding ?? null),
            'b0_30' => Num::dec($row->b0_30 ?? null),
            'b31_60' => Num::dec($row->b31_60 ?? null),
            'b61_90' => Num::dec($row->b61_90 ?? null),
            'b90_plus' => Num::dec($row->b90_plus ?? null),
        ];
    }

    /**
     * Outstanding per kind (dashboards).
     *
     * @param  list<int>  $projectIds
     * @return array{vendor: Decimal, subcontract: Decimal, labour: Decimal, total: Decimal}
     */
    public function outstandingByKind(int $companyId, array $projectIds, string $asOf): array
    {
        $sums = DB::query()->fromSub($this->base($companyId, $projectIds, $asOf), 't')
            ->groupBy('kind')->selectRaw('kind, SUM(outstanding) as total')->pluck('total', 'kind');
        $out = ['vendor' => Num::dec($sums['vendor'] ?? null), 'subcontract' => Num::dec($sums['subcontract'] ?? null), 'labour' => Num::dec($sums['labour'] ?? null)];

        return $out + ['total' => Decimal::sum(array_values($out))];
    }

    /**
     * Vendor bills submitted but not yet approved — not payables, reported separately.
     *
     * @param  list<int>  $projectIds
     * @return array{count: int, amount: Decimal}
     */
    public function pendingVendorBills(int $companyId, array $projectIds, string $asOf): array
    {
        $row = DB::table('vendor_bills')->where('company_id', $companyId)->whereIn('project_id', $projectIds ?: [0])
            ->where('status', 'submitted')->whereNull('deleted_at')->where('vendor_invoice_date', '<=', Sql::eod($asOf))
            ->selectRaw('COUNT(*) as cnt, SUM(net_payable) as amount')->first();

        return ['count' => (int) ($row->cnt ?? 0), 'amount' => Num::dec($row->amount ?? null)];
    }

    private function vendor(int $companyId, array $projectIds, string $asOf, array $filters): Builder
    {
        return DB::table('vendor_bills as b')
            ->join('projects as pr', 'pr.id', '=', 'b.project_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'b.vendor_id')
            ->leftJoinSub(Settlements::allocated($companyId, 'vendor_bill', $asOf), 's', 's.payable_id', '=', 'b.id')
            ->where('b.company_id', $companyId)
            ->whereIn('b.project_id', $projectIds ?: [0])
            ->whereIn('b.status', ['approved', 'partially_paid', 'paid'])
            ->whereNull('b.deleted_at')
            ->where('b.vendor_invoice_date', '<=', Sql::eod($asOf))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('b.vendor_id', $id))
            ->selectRaw("'vendor' as kind, b.id, b.project_id, pr.code as project_code, v.name as party,
                b.bill_number as document, b.vendor_invoice_no as reference, b.vendor_invoice_date as doc_date,
                COALESCE(b.due_date, b.vendor_invoice_date) as age_from,
                b.total_amount as gross, b.tds_amount as deductions, b.net_payable as due,
                COALESCE(s.settled, 0) as settled, b.net_payable - COALESCE(s.settled, 0) as outstanding, "
                .Sql::days('COALESCE(b.due_date, b.vendor_invoice_date)', '?').' as age', [$asOf]);
    }

    private function subcontract(int $companyId, array $projectIds, string $asOf, array $filters): Builder
    {
        return DB::table('subcontractor_bills as b')
            ->join('projects as pr', 'pr.id', '=', 'b.project_id')
            ->leftJoin('subcontractors as sc', 'sc.id', '=', 'b.subcontractor_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'b.work_order_id')
            ->leftJoinSub(Settlements::allocated($companyId, 'subcontractor_bill', $asOf), 's', 's.payable_id', '=', 'b.id')
            ->leftJoinSub(Settlements::released($companyId, 'subcontractor_bill', $asOf), 'rr', 'rr.releasable_id', '=', 'b.id')
            ->where('b.company_id', $companyId)
            ->whereIn('b.project_id', $projectIds ?: [0])
            ->whereIn('b.status', ['certified', 'partially_paid', 'paid'])
            ->whereNull('b.deleted_at')
            ->where('b.bill_date', '<=', Sql::eod($asOf))
            ->when($filters['subcontractor_id'] ?? null, fn ($q, $id) => $q->where('b.subcontractor_id', $id))
            ->selectRaw("'subcontract' as kind, b.id, b.project_id, pr.code as project_code, sc.name as party,
                b.bill_number as document, wo.wo_number as reference, b.bill_date as doc_date, b.bill_date as age_from,
                b.gross_amount + b.tax_amount as gross,
                b.retention_amount + b.advance_recovery + b.tds_amount + b.other_deductions - COALESCE(rr.released, 0) as deductions,
                b.net_payable + COALESCE(rr.released, 0) as due,
                COALESCE(s.settled, 0) as settled, b.net_payable + COALESCE(rr.released, 0) - COALESCE(s.settled, 0) as outstanding, "
                .Sql::days('b.bill_date', '?').' as age', [$asOf]);
    }

    private function labour(int $companyId, array $projectIds, string $asOf): Builder
    {
        return DB::table('labour_payments as b')
            ->join('projects as pr', 'pr.id', '=', 'b.project_id')
            ->leftJoinSub(Settlements::allocated($companyId, 'labour_payment', $asOf), 's', 's.payable_id', '=', 'b.id')
            ->where('b.company_id', $companyId)
            ->whereIn('b.project_id', $projectIds ?: [0])
            ->where(fn ($q) => $q->where('b.status', 'approved')->orWhere(fn ($p) => $p->where('b.status', 'paid')->where('b.paid_amount', '>', 0)))
            ->whereNull('b.deleted_at')
            ->where('b.period_to', '<=', Sql::eod($asOf))
            ->selectRaw("'labour' as kind, b.id, b.project_id, pr.code as project_code, 'Labour batch' as party,
                b.payment_number as document, NULL as reference, b.period_to as doc_date, b.period_to as age_from,
                b.total_gross + b.total_ot as gross, b.total_deductions as deductions, b.total_net as due,
                COALESCE(s.settled, 0) as settled, b.total_net - COALESCE(s.settled, 0) as outstanding, "
                .Sql::days('b.period_to', '?').' as age', [$asOf]);
    }
}

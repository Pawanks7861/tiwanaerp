<?php

namespace App\Queries\Reports;

use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash movement — never the cost ledger. Same sources as the Phase 7 cash-flow page, in SQL:
 *  - approved receipts (in) and payments (out);
 *  - paid expenses not paid from petty cash (out); petty-cash expenses already left with the float;
 *  - petty-cash funding (out of the company into a float) and returns (back in); reversal rows
 *    carry their own sign;
 *  - labour batches marked paid directly in Phase 6 (out), which have no Finance payment.
 */
final class CashFlowQuery
{
    public const TYPES = [
        'receipt' => 'Receipt', 'payment' => 'Payment', 'expense' => 'Expense',
        'petty_cash_funding' => 'Petty cash funding', 'petty_cash_return' => 'Petty cash return', 'labour_direct' => 'Labour paid directly',
    ];

    /**
     * Union of all cash rows (unfiltered by date), one shape.
     *
     * @param  list<int>  $projectIds
     */
    public function union(int $companyId, array $projectIds, ?string $mode = null): Builder
    {
        $ids = $projectIds ?: [0];
        $partyName = "CASE p.party_type
                WHEN 'client' THEN (SELECT company_name FROM clients WHERE clients.id = p.party_id)
                WHEN 'vendor' THEN (SELECT name FROM vendors WHERE vendors.id = p.party_id)
                WHEN 'subcontractor' THEN (SELECT name FROM subcontractors WHERE subcontractors.id = p.party_id)
                ELSE (SELECT payment_number FROM labour_payments WHERE labour_payments.id = p.party_id) END";

        $payments = DB::table('payments as p')
            ->join('projects as pr', 'pr.id', '=', 'p.project_id')
            ->where('p.company_id', $companyId)->whereIn('p.project_id', $ids)
            ->where('p.status', 'approved')->whereNull('p.deleted_at')
            ->when($mode, fn ($q) => $q->where('p.mode', $mode))
            ->selectRaw("p.payment_date as txn_date, p.direction as type, p.project_id, pr.code as project_code, 'payment' as source,
                p.id as source_id, p.payment_number as document, {$partyName} as party, p.mode as mode, p.bank_reference as reference,
                CASE WHEN p.direction = 'receipt' THEN p.amount ELSE 0 END as inflow,
                CASE WHEN p.direction = 'payment' THEN p.amount ELSE 0 END as outflow");

        $expenses = DB::table('expenses as e')
            ->join('projects as pr', 'pr.id', '=', 'e.project_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'e.vendor_id')
            ->where('e.company_id', $companyId)->whereIn('e.project_id', $ids)
            ->where('e.status', 'paid')->where('e.payment_mode', '!=', 'petty_cash')->whereNull('e.deleted_at')
            ->when($mode, fn ($q) => $q->where('e.payment_mode', $mode))
            ->selectRaw("e.paid_on as txn_date, 'expense' as type, e.project_id, pr.code as project_code, 'expense' as source,
                e.id as source_id, e.expense_number as document, COALESCE(v.name, e.payee_name) as party, e.payment_mode as mode,
                e.payment_reference as reference, 0 as inflow, e.total_amount as outflow");

        $parts = [$payments, $expenses];

        if (! $mode) {
            $parts[] = DB::table('petty_cash_transactions as t')
                ->join('petty_cash_accounts as a', 'a.id', '=', 't.petty_cash_account_id')
                ->join('projects as pr', 'pr.id', '=', 'a.project_id')
                ->leftJoin('users as u', 'u.id', '=', 'a.holder_user_id')
                ->where('t.company_id', $companyId)->whereIn('a.project_id', $ids)
                ->whereIn('t.type', ['fund_in', 'return_out'])
                ->selectRaw("t.txn_date as txn_date, CASE WHEN t.type = 'fund_in' THEN 'petty_cash_funding' ELSE 'petty_cash_return' END as type,
                    a.project_id, pr.code as project_code, 'petty_cash' as source, a.id as source_id, a.name as document, u.name as party,
                    'petty_cash' as mode, t.remarks as reference,
                    CASE WHEN t.type = 'return_out' THEN t.amount ELSE 0 END as inflow,
                    CASE WHEN t.type = 'fund_in' THEN t.amount ELSE 0 END as outflow");

            $parts[] = DB::table('labour_payments as l')
                ->join('projects as pr', 'pr.id', '=', 'l.project_id')
                ->where('l.company_id', $companyId)->whereIn('l.project_id', $ids)
                ->where('l.status', 'paid')->where('l.paid_amount', 0)->whereNotNull('l.paid_on')->whereNull('l.deleted_at')
                ->selectRaw("l.paid_on as txn_date, 'labour_direct' as type, l.project_id, pr.code as project_code, 'labour_payment' as source,
                    l.id as source_id, l.payment_number as document, 'Labour batch' as party, 'labour' as mode, l.payment_reference as reference,
                    0 as inflow, l.total_net as outflow");
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    /**
     * Filtered rows in the period with a running balance (opening = net of earlier rows).
     *
     * @param  list<int>  $projectIds
     * @param  array{type?: string|null, mode?: string|null, party?: string|null}  $filters
     */
    public function rows(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        return $this->filtered($companyId, $projectIds, $filters)
            ->where('txn_date', '>=', $from)
            ->where('txn_date', '<=', Sql::eod($to))
            ->select('*')
            ->selectRaw('SUM(inflow - outflow) OVER (ORDER BY txn_date, source, source_id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running');
    }

    /**
     * Net cash before the period (same filters) — the running balance starts here.
     *
     * @param  list<int>  $projectIds
     */
    public function opening(int $companyId, array $projectIds, string $from, array $filters = []): Decimal
    {
        $row = $this->filtered($companyId, $projectIds, $filters)->where('txn_date', '<', $from)
            ->selectRaw('SUM(inflow) - SUM(outflow) as net')->first();

        return Num::dec($row->net ?? null);
    }

    /**
     * @param  list<int>  $projectIds
     * @return array{inflow: Decimal, outflow: Decimal, net: Decimal, count: int}
     */
    public function totals(int $companyId, array $projectIds, string $from, string $to, array $filters = []): array
    {
        $row = $this->filtered($companyId, $projectIds, $filters)
            ->where('txn_date', '>=', $from)->where('txn_date', '<=', Sql::eod($to))
            ->selectRaw('COUNT(*) as cnt, SUM(inflow) as inflow, SUM(outflow) as outflow')->first();
        $in = Num::dec($row->inflow ?? null);
        $out = Num::dec($row->outflow ?? null);

        return ['inflow' => $in, 'outflow' => $out, 'net' => $in->minus($out), 'count' => (int) ($row->cnt ?? 0)];
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, array{inflow: Decimal, outflow: Decimal}> YYYY-MM => totals
     */
    public function monthly(int $companyId, array $projectIds, string $from, string $to, array $filters = []): array
    {
        $month = Sql::month('txn_date');
        $out = [];
        $this->filtered($companyId, $projectIds, $filters)
            ->where('txn_date', '>=', $from)->where('txn_date', '<=', Sql::eod($to))
            ->groupByRaw($month)
            ->selectRaw("{$month} as ym, SUM(inflow) as inflow, SUM(outflow) as outflow")
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->ym] = ['inflow' => Num::dec($row->inflow), 'outflow' => Num::dec($row->outflow)];
            });
        ksort($out);

        return $out;
    }

    private function filtered(int $companyId, array $projectIds, array $filters): Builder
    {
        $party = trim((string) ($filters['party'] ?? ''));

        return DB::query()->fromSub($this->union($companyId, $projectIds, $filters['mode'] ?? null), 'c')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($party !== '', fn ($q) => $q->whereRaw('LOWER(party) LIKE ?', ['%'.mb_strtolower($party).'%']));
    }
}

<?php

namespace App\Queries\Reports;

use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Client receivables as of a date: certified RA bills (certified, partially paid, paid) dated on
 * or before it. due = net payable + retention released by approved releases; received = Σ
 * allocations of approved receipts dated on or before it. Cancelled receipts drop out because
 * only approved payments count. Aging runs from the invoice date (RA bills have no due date).
 */
final class ReceivablesQuery
{
    public const STATES = ['certified', 'partially_paid', 'paid'];

    /**
     * @param  list<int>  $projectIds
     * @param  array{client_id?: int|null, status?: string|null, search?: string|null}  $filters
     */
    public function base(int $companyId, array $projectIds, string $asOf, array $filters = []): Builder
    {
        $due = 'i.net_payable + COALESCE(rr.released, 0)';
        $outstanding = "{$due} - COALESCE(s.settled, 0)";
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('client_invoices as i')
            ->join('projects as pr', 'pr.id', '=', 'i.project_id')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->leftJoinSub(Settlements::allocated($companyId, 'client_invoice', $asOf), 's', 's.payable_id', '=', 'i.id')
            ->leftJoinSub(Settlements::released($companyId, 'client_invoice', $asOf), 'rr', 'rr.releasable_id', '=', 'i.id')
            ->where('i.company_id', $companyId)
            ->whereIn('i.project_id', $projectIds ?: [0])
            ->whereIn('i.status', self::STATES)
            ->whereNull('i.deleted_at')
            ->where('i.invoice_date', '<=', Sql::eod($asOf))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('i.client_id', $id))
            ->when(($filters['status'] ?? null) === 'outstanding', fn ($q) => $q->whereRaw("{$outstanding} > 0"))
            ->when(($filters['status'] ?? null) === 'settled', fn ($q) => $q->whereRaw("{$outstanding} <= 0"))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('i.invoice_number', 'like', "%{$search}%")->orWhere('c.company_name', 'like', "%{$search}%")))
            ->select('i.id', 'i.project_id', 'pr.code as project_code', 'c.company_name as client', 'i.invoice_number', 'i.ra_sequence',
                'i.invoice_date', 'i.gross_amount', 'i.invoice_total', 'i.net_payable', 'i.retention_amount', 'i.received_amount', 'i.status')
            ->selectRaw('COALESCE(rr.released, 0) as released')
            ->selectRaw('i.retention_amount - COALESCE(rr.released, 0) as retention_held')
            ->selectRaw("{$due} as due")
            ->selectRaw('COALESCE(s.settled, 0) as received')
            ->selectRaw("{$outstanding} as outstanding")
            ->selectRaw(Sql::days('i.invoice_date', '?').' as age', [$asOf]);
    }

    /**
     * @return array{invoice_total: Decimal, net_payable: Decimal, retention_held: Decimal, due: Decimal, received: Decimal, outstanding: Decimal, b0_30: Decimal, b31_60: Decimal, b61_90: Decimal, b90_plus: Decimal, count: int}
     */
    public function totals(Builder $base): array
    {
        $row = DB::query()->fromSub($base, 'x')
            ->selectRaw('COUNT(*) as cnt, SUM(invoice_total) as invoice_total, SUM(net_payable) as net_payable, SUM(retention_held) as retention_held,
                SUM(due) as due, SUM(received) as received, SUM(outstanding) as outstanding, '.Settlements::bucketSelect())
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'invoice_total' => Num::dec($row->invoice_total ?? null),
            'net_payable' => Num::dec($row->net_payable ?? null),
            'retention_held' => Num::dec($row->retention_held ?? null),
            'due' => Num::dec($row->due ?? null),
            'received' => Num::dec($row->received ?? null),
            'outstanding' => Num::dec($row->outstanding ?? null),
            'b0_30' => Num::dec($row->b0_30 ?? null),
            'b31_60' => Num::dec($row->b31_60 ?? null),
            'b61_90' => Num::dec($row->b61_90 ?? null),
            'b90_plus' => Num::dec($row->b90_plus ?? null),
        ];
    }

    /**
     * Billed (certified value before GST), billed incl. GST, received and outstanding per project.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{billed: Decimal, invoiced: Decimal, received: Decimal, outstanding: Decimal}>
     */
    public function byProject(int $companyId, array $projectIds, string $asOf): array
    {
        return DB::query()->fromSub($this->base($companyId, $projectIds, $asOf), 'x')
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(gross_amount) as billed, SUM(invoice_total) as invoiced, SUM(received) as received, SUM(outstanding) as outstanding')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->project_id => [
                'billed' => Num::dec($r->billed), 'invoiced' => Num::dec($r->invoiced),
                'received' => Num::dec($r->received), 'outstanding' => Num::dec($r->outstanding),
            ]])->all();
    }
}

<?php

namespace App\Queries\Reports;

use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders in a period (by PO date) with value ordered, received (accepted quantity on
 * approved GRNs at the PO line's taxable rate), billed (approved vendor bills against the PO)
 * and still open (approved-order POs only). Purchase value = taxable value excluding GST.
 */
final class ProcurementQuery
{
    /**
     * @param  list<int>  $projectIds
     * @param  array{vendor_id?: int|null, status?: string|null, search?: string|null}  $filters
     */
    public function orders(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        $accepted = DB::table('grn_items as gi')
            ->join('grns as g', 'g.id', '=', 'gi.grn_id')
            ->where('g.company_id', $companyId)->where('g.status', 'approved')->whereNull('g.deleted_at')
            ->groupBy('gi.purchase_order_item_id')
            ->selectRaw('gi.purchase_order_item_id, SUM(gi.accepted_qty) as accepted');

        $lines = DB::table('purchase_order_items as poi')
            ->leftJoinSub($accepted, 'r', 'r.purchase_order_item_id', '=', 'poi.id')
            ->whereIn('poi.purchase_order_id', fn ($q) => $q->select('id')->from('purchase_orders')
                ->where('company_id', $companyId)->whereIn('project_id', $projectIds ?: [0]))
            ->groupBy('poi.purchase_order_id')
            ->selectRaw('poi.purchase_order_id, COUNT(*) as items,
                SUM(CASE WHEN poi.quantity > 0 THEN poi.taxable_amount * (CASE WHEN COALESCE(r.accepted, 0) > poi.quantity THEN poi.quantity ELSE COALESCE(r.accepted, 0) END) / poi.quantity ELSE 0 END) as received_value');

        $billed = DB::table('vendor_bills')
            ->where('company_id', $companyId)->whereIn('status', ['approved', 'partially_paid', 'paid'])->whereNull('deleted_at')->whereNotNull('purchase_order_id')
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, SUM(subtotal) as billed');

        $search = trim((string) ($filters['search'] ?? ''));
        $open = "CASE WHEN po.status IN ('approved', 'partially_received', 'received') AND po.taxable_amount > COALESCE(ln.received_value, 0)
            THEN po.taxable_amount - COALESCE(ln.received_value, 0) ELSE 0 END";

        return DB::table('purchase_orders as po')
            ->join('projects as pr', 'pr.id', '=', 'po.project_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->leftJoinSub($lines, 'ln', 'ln.purchase_order_id', '=', 'po.id')
            ->leftJoinSub($billed, 'vb', 'vb.purchase_order_id', '=', 'po.id')
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $projectIds ?: [0])
            ->whereNull('po.deleted_at')
            ->where('po.po_date', '>=', $from)->where('po.po_date', '<=', Sql::eod($to))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('po.vendor_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('po.status', $s), fn ($q) => $q->whereNotIn('po.status', ['draft', 'rejected']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('po.po_number', 'like', "%{$search}%")->orWhere('v.name', 'like', "%{$search}%")))
            ->select('po.id', 'po.project_id', 'pr.code as project_code', 'po.po_number', 'v.name as vendor', 'po.po_date', 'po.delivery_date', 'po.status',
                'po.taxable_amount')
            ->selectRaw('po.cgst_amount + po.sgst_amount + po.igst_amount as tax_amount, po.grand_total')
            ->selectRaw('COALESCE(ln.items, 0) as items, COALESCE(ln.received_value, 0) as received_value, COALESCE(vb.billed, 0) as billed')
            ->selectRaw("{$open} as open_value");
    }

    /**
     * @return array<string, string|int>
     */
    public function totals(Builder $orders): array
    {
        $row = DB::query()->fromSub($orders, 'x')
            ->selectRaw('COUNT(*) as cnt, SUM(taxable_amount) as taxable_amount, SUM(tax_amount) as tax_amount, SUM(grand_total) as grand_total,
                SUM(received_value) as received_value, SUM(billed) as billed, SUM(open_value) as open_value')->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'taxable_amount' => Num::money($row->taxable_amount ?? null),
            'tax_amount' => Num::money($row->tax_amount ?? null),
            'grand_total' => Num::money($row->grand_total ?? null),
            'received_value' => Num::money($row->received_value ?? null),
            'billed' => Num::money($row->billed ?? null),
            'open_value' => Num::money($row->open_value ?? null),
        ];
    }

    /**
     * Approved purchase value (taxable, excl. GST) of POs dated in the period — dashboard KPI.
     *
     * @param  list<int>  $projectIds
     */
    public function purchaseValue(int $companyId, array $projectIds, ?string $from, ?string $to): string
    {
        return Num::money(DB::table('purchase_orders')->where('company_id', $companyId)->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', ['approved', 'partially_received', 'received', 'closed'])->whereNull('deleted_at')
            ->when($from, fn ($q) => $q->where('po_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('po_date', '<=', Sql::eod($to)))
            ->sum('taxable_amount'));
    }
}

<?php

namespace App\Queries\Reports;

use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Committed cost (architecture O.5): the part of approved purchase and work orders not yet
 * delivered / billed — money the project has promised but not yet spent.
 *
 *  - Purchase: for every line of an approved-order PO (approved, partially received, received),
 *    taxable value × open quantity ÷ ordered quantity, where open = ordered − accepted on
 *    approved GRNs (floored at 0). GST and freight are excluded (they are not project cost).
 *    Received goods leave the commitment: they become stock and reach the ledger as material
 *    cost when issued, so nothing is counted twice. Closed / cancelled POs commit nothing more.
 *  - Work orders: for every billable WO (approved, in progress, completed), the WO subtotal less
 *    the subcontract cost already posted to the ledger from its certified bills (net of
 *    reversals), floored at 0. Closed / cancelled WOs commit nothing more.
 *
 * Each line / order is rounded HALF_UP to paise before summing so totals reconcile by hand.
 */
final class CommittedCostQuery
{
    public const PO_STATES = ['approved', 'partially_received', 'received'];

    public const WO_STATES = ['approved', 'in_progress', 'completed'];

    public function __construct(private readonly CostLedgerQuery $ledger) {}

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{material: Decimal, subcontract: Decimal, total: Decimal}>
     */
    public function byProject(int $companyId, array $projectIds): array
    {
        $out = [];
        $add = function (int $projectId, string $head, Decimal $amount) use (&$out) {
            $out[$projectId] ??= ['material' => Decimal::zero(), 'subcontract' => Decimal::zero(), 'total' => Decimal::zero()];
            $out[$projectId][$head] = $out[$projectId][$head]->plus($amount);
            $out[$projectId]['total'] = $out[$projectId]['total']->plus($amount);
        };

        foreach ($this->purchaseLines($companyId, $projectIds) as $line) {
            $add((int) $line->project_id, 'material', $this->openLineValue($line));
        }
        foreach ($this->workOrders($companyId, $projectIds) as $wo) {
            $add((int) $wo['project_id'], 'subcontract', $wo['open']);
        }

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array{material: Decimal, subcontract: Decimal, total: Decimal}
     */
    public function total(int $companyId, array $projectIds): array
    {
        $sum = ['material' => Decimal::zero(), 'subcontract' => Decimal::zero(), 'total' => Decimal::zero()];
        foreach ($this->byProject($companyId, $projectIds) as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value->plus($row[$key]);
            }
        }

        return $sum;
    }

    /**
     * Open PO lines with what is still to be received.
     *
     * @param  list<int>  $projectIds
     * @return list<object>
     */
    public function purchaseLines(int $companyId, array $projectIds): array
    {
        $accepted = DB::table('grn_items as gi')
            ->join('grns as g', 'g.id', '=', 'gi.grn_id')
            ->where('g.company_id', $companyId)
            ->where('g.status', 'approved')
            ->whereNull('g.deleted_at')
            ->groupBy('gi.purchase_order_item_id')
            ->selectRaw('gi.purchase_order_item_id, SUM(gi.accepted_qty) as accepted');

        return DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoinSub($accepted, 'r', 'r.purchase_order_item_id', '=', 'poi.id')
            ->where('po.company_id', $companyId)
            ->whereIn('po.project_id', $projectIds ?: [0])
            ->whereIn('po.status', self::PO_STATES)
            ->whereNull('po.deleted_at')
            ->where('poi.quantity', '>', 0)
            ->whereRaw('poi.quantity > COALESCE(r.accepted, 0)')
            ->select('po.project_id', 'po.id as purchase_order_id', 'po.po_number', 'poi.id', 'poi.quantity', 'poi.taxable_amount')
            ->selectRaw('COALESCE(r.accepted, 0) as accepted')
            ->get()->all();
    }

    /**
     * Billable work orders with their posted cost and open value.
     *
     * @param  list<int>  $projectIds
     * @return list<array{id: int, project_id: int, wo_number: string, value: Decimal, posted: Decimal, open: Decimal}>
     */
    public function workOrders(int $companyId, array $projectIds): array
    {
        $orders = DB::table('work_orders')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', self::WO_STATES)
            ->whereNull('deleted_at')
            ->get(['id', 'project_id', 'wo_number', 'subtotal']);
        if ($orders->isEmpty()) {
            return [];
        }

        $posted = $this->ledger->subcontractPostedByWorkOrder($companyId, $projectIds);

        return $orders->map(function ($wo) use ($posted) {
            $value = Num::dec($wo->subtotal);
            $done = $posted[(int) $wo->id] ?? Decimal::zero();
            $open = $value->minus($done);

            return [
                'id' => (int) $wo->id,
                'project_id' => (int) $wo->project_id,
                'wo_number' => $wo->wo_number,
                'value' => $value,
                'posted' => $done,
                'open' => $open->isNegative() ? Decimal::zero() : Decimal::of($open->toMoney()),
            ];
        })->all();
    }

    private function openLineValue(object $line): Decimal
    {
        $quantity = Num::dec($line->quantity);
        $open = $quantity->minus(Num::dec($line->accepted));
        if (! $open->isPositive() || $quantity->isZero()) {
            return Decimal::zero();
        }

        return Decimal::of(Num::dec($line->taxable_amount)->times($open)->dividedBy($quantity)->toMoney());
    }
}

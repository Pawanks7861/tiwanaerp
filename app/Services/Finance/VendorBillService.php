<?php

namespace App\Services\Finance;

use App\Enums\CostHead;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Finance\VendorBillType;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Finance\VendorBill;
use App\Models\Finance\VendorBillItem;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Services\Tax\GstCalculator;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor bills: draft → submitted (engine: PM → Director) → approved → partially_paid / paid.
 *
 * PO bills (3-way match): each line is an approved GRN line of the PO; billed quantity ≤ accepted
 * quantity − quantity on the vendor's other submitted / approved bills; rate, discount and GST
 * rate come from the PO line and tax type / place of supply from the PO. The material cost was
 * already posted by the inventory issue, so a PO bill writes no project cost.
 *
 * Direct bills (non-stock goods / services) carry free lines and an explicit cost head; approval
 * posts each line's taxable amount (excluding GST) to the project cost ledger once.
 *
 * Totals (server-side): line amounts via GstCalculator; subtotal = Σ taxable; TDS = round(subtotal
 * × %, 2); net payable = total − TDS. The vendor invoice number is unique per vendor.
 */
class VendorBillService
{
    use ResolvesProjectRefs;

    /** PO statuses that can be billed. */
    private const BILLABLE_PO = [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProjectCostLedgerService $costs,
        private readonly GstCalculator $gst,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): VendorBill
    {
        return DB::transaction(function () use ($project, $data) {
            $type = VendorBillType::tryFrom((string) ($data['bill_type'] ?? ''))
                ?? throw ValidationException::withMessages(['bill_type' => 'Choose a PO bill or a direct bill.']);

            $bill = new VendorBill;
            $bill->forceFill([
                'project_id' => $project->id,
                'bill_type' => $type,
                'bill_number' => $this->numbers->next('vendor_bill', $project),
                'status' => VendorBillStatus::Draft,
            ]);
            $this->fillAndLines($bill, $project, $data);

            return $bill;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(VendorBill $bill, array $data): VendorBill
    {
        return DB::transaction(function () use ($bill, $data) {
            $locked = VendorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $this->fillAndLines($locked, $locked->project, $data);

            return $locked;
        });
    }

    public function delete(VendorBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $locked = VendorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->items()->get()->each->delete();
            $locked->delete();
        });
    }

    public function submit(VendorBill $bill, User $user): void
    {
        DB::transaction(function () use ($bill, $user) {
            $locked = VendorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if (! $locked->isDirect()) {
                $order = PurchaseOrder::query()->whereKey($locked->purchase_order_id)->lockForUpdate()->firstOrFail();
                $this->assertPoBillable($order);
                $this->assertGrnQuantities($locked, includePending: true);
            }
            $this->assertInvoiceUnique($locked->vendor_id, $locked->vendor_invoice_no, $locked->id);
            if (! Decimal::of($locked->total_amount)->isPositive()) {
                throw ValidationException::withMessages(['bill' => 'The bill has no amount.']);
            }

            $this->approvals->submit($locked, $user);
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the engine's transaction). Re-checks the 3-way match under the PO
     * lock; a direct bill posts its cost. The final approver needs vendor_bills.approve. Idempotent.
     */
    public function approve(VendorBill $bill, ?int $approverId): void
    {
        DB::transaction(function () use ($bill, $approverId) {
            if ($bill->purchase_order_id) {
                PurchaseOrder::query()->whereKey($bill->purchase_order_id)->lockForUpdate()->firstOrFail();
            }
            $locked = VendorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isApproved()) {
                return;
            }
            if ($locked->status !== VendorBillStatus::Submitted) {
                throw ValidationException::withMessages(['bill' => 'Only a submitted vendor bill can be approved.']);
            }
            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, 'vendor_bills.approve')) {
                throw ValidationException::withMessages(['approval' => 'Approving a vendor bill needs the vendor_bills.approve permission.']);
            }

            if ($locked->isDirect()) {
                foreach ($locked->items()->get() as $item) {
                    if (Decimal::of($item->taxable_amount)->isZero()) {
                        continue;
                    }
                    $this->costs->post(
                        source: $item,
                        projectId: $locked->project_id,
                        head: $locked->cost_head,
                        amount: Decimal::of($item->taxable_amount),
                        date: $locked->vendor_invoice_date->toDateString(),
                        boqItemId: $locked->boq_item_id,
                        boqLineUid: $locked->boq_line_uid,
                        taskId: $locked->task_id,
                        remarks: "{$locked->bill_number} ({$locked->vendor_invoice_no})",
                        userId: $approverId,
                    );
                }
            } else {
                $this->assertGrnQuantities($locked, includePending: false);
            }

            $locked->forceFill([
                'status' => VendorBillStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Approved GRN lines of a PO with the quantity still billable (for the bill form).
     *
     * @return list<array<string, mixed>>
     */
    public function billableGrnLines(PurchaseOrder $order, ?VendorBill $except = null): array
    {
        $grnItems = $this->approvedGrnItems($order);
        $billed = $this->billedQuantities($grnItems->modelKeys(), [VendorBillStatus::Submitted, ...VendorBillStatus::approvedStates()], $except?->id);

        return $grnItems->map(fn (GrnItem $g) => [
            'grn_item_id' => $g->id,
            'grn_number' => $g->grn?->grn_number,
            'receipt_date' => $g->grn?->receipt_date?->toDateString(),
            'material' => $g->purchaseOrderItem?->description,
            'unit' => $g->unit?->symbol,
            'po_qty' => $g->purchaseOrderItem?->quantity,
            'received_qty' => $g->received_qty,
            'accepted_qty' => $g->accepted_qty,
            'billed_qty' => ($billed[$g->id] ?? Decimal::zero())->toQuantity(),
            'balance_qty' => Decimal::of($g->accepted_qty)->minus($billed[$g->id] ?? '0')->toQuantity(),
            'rate' => $g->purchaseOrderItem?->rate,
            'discount_percent' => $g->purchaseOrderItem?->discount_percent,
            'tax_rate_id' => $g->purchaseOrderItem?->tax_rate_id,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillAndLines(VendorBill $bill, Project $project, array $data): void
    {
        $header = [
            'vendor_invoice_no' => trim((string) ($data['vendor_invoice_no'] ?? '')),
            'vendor_invoice_date' => $data['vendor_invoice_date'],
            'due_date' => $data['due_date'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
        if ($header['vendor_invoice_no'] === '') {
            throw ValidationException::withMessages(['vendor_invoice_no' => 'Enter the vendor invoice number.']);
        }
        if ($header['due_date'] && $header['due_date'] < $header['vendor_invoice_date']) {
            throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the invoice date.']);
        }
        $tdsPercent = $this->percent($data['tds_percent'] ?? null, 'tds_percent');

        if ($bill->isDirect()) {
            $vendor = Vendor::query()->whereKey((int) ($data['vendor_id'] ?? 0))->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['vendor_id' => 'Choose an active vendor.']);
            $head = CostHead::tryFrom((string) ($data['cost_head'] ?? ''))
                ?? throw ValidationException::withMessages(['cost_head' => 'Classify the bill with a cost head; it is posted to the project cost on approval.']);
            if (blank($project->state_code)) {
                throw ValidationException::withMessages(['vendor_id' => 'Set the project state (place of supply) first.']);
            }
            $type = $this->gst->taxType($vendor->state_code, $project->state_code);
            $task = $this->task($project, $data['task_id'] ?? null, 'task_id');
            [$boqItemId, $boqLineUid] = $this->boqRefOfTask($task?->id);
            if (filled($data['boq_item_id'] ?? null)) {
                $boq = $this->boqItem($project, $data['boq_item_id'], 'boq_item_id');
                [$boqItemId, $boqLineUid] = [$boq->id, $boq->line_uid];
            }
            $this->assertInvoiceUnique($vendor->id, $header['vendor_invoice_no'], $bill->id);

            $bill->forceFill([
                ...$header,
                'vendor_id' => $vendor->id,
                'purchase_order_id' => null,
                'cost_head' => $head,
                'task_id' => $task?->id,
                'boq_item_id' => $boqItemId,
                'boq_line_uid' => $boqLineUid,
                'place_of_supply_state' => $project->state_code,
                'tax_type' => $type,
            ])->save();
            $this->writeDirectLines($bill, $data['items'] ?? []);
        } else {
            $order = PurchaseOrder::query()->where('project_id', $project->id)
                ->whereKey((int) ($data['purchase_order_id'] ?? $bill->purchase_order_id ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['purchase_order_id' => 'Choose a purchase order of this project.']);
            if ($bill->exists && $bill->purchase_order_id && (int) $bill->purchase_order_id !== $order->id) {
                throw ValidationException::withMessages(['purchase_order_id' => 'The purchase order of a bill cannot be changed; delete the draft and start again.']);
            }
            $this->assertPoBillable($order);
            $this->assertInvoiceUnique($order->vendor_id, $header['vendor_invoice_no'], $bill->id);

            $bill->forceFill([
                ...$header,
                'vendor_id' => $order->vendor_id,
                'purchase_order_id' => $order->id,
                'cost_head' => null,
                'task_id' => null,
                'boq_item_id' => null,
                'boq_line_uid' => null,
                'place_of_supply_state' => $order->place_of_supply_state,
                'tax_type' => $order->tax_type,
            ])->save();
            $this->writePoLines($bill, $order, $data['items'] ?? []);
            $this->assertGrnQuantities($bill, includePending: true);
        }

        $this->recalculate($bill, $tdsPercent);
    }

    /**
     * @param  list<array<string, mixed>>  $rows  grn_item_id, quantity
     */
    private function writePoLines(VendorBill $bill, PurchaseOrder $order, array $rows): void
    {
        $grnItems = $this->approvedGrnItems($order)->keyBy('id');
        $existing = VendorBillItem::query()->where('vendor_bill_id', $bill->id)->get()->keyBy('grn_item_id');
        $taxRates = TaxRate::query()->withTrashed()->get()->keyBy('id');

        $kept = [];
        $sort = 0;
        foreach (array_values($rows) as $index => $row) {
            $key = "items.{$index}";
            $grnItem = $grnItems->get((int) ($row['grn_item_id'] ?? 0))
                ?? throw ValidationException::withMessages(["{$key}.grn_item_id" => 'This line is not on an approved GRN of the purchase order.']);
            if (isset($kept[$grnItem->id])) {
                throw ValidationException::withMessages(["{$key}.grn_item_id" => 'Each GRN line can appear once per bill.']);
            }
            $qty = $this->quantity($row['quantity'] ?? null, "{$key}.quantity", allowZero: true);
            if ($qty->isZero()) {
                continue;
            }
            $kept[$grnItem->id] = true;
            $poItem = $grnItem->purchaseOrderItem;
            $tax = $poItem->tax_rate_id ? $taxRates->get($poItem->tax_rate_id) : null;
            $line = $this->gst->line($qty->toQuantity(), (string) $poItem->rate, (string) $poItem->discount_percent, $tax, $bill->tax_type);

            ($existing->get($grnItem->id) ?? new VendorBillItem)->forceFill([
                'vendor_bill_id' => $bill->id,
                'purchase_order_item_id' => $poItem->id,
                'grn_item_id' => $grnItem->id,
                'material_id' => $poItem->material_id,
                'description' => $poItem->description,
                'hsn_sac' => $poItem->hsn_sac,
                'unit_id' => $poItem->unit_id,
                'quantity' => $qty->toQuantity(),
                'rate' => $poItem->rate,
                'discount_percent' => $poItem->discount_percent,
                'tax_rate_id' => $tax?->id,
                'sort_order' => $sort++,
                ...$line,
            ])->save();
        }
        if ($kept === []) {
            throw ValidationException::withMessages(['items' => 'Bill a quantity on at least one GRN line.']);
        }
        foreach ($existing->reject(fn (VendorBillItem $i) => isset($kept[$i->grn_item_id])) as $dropped) {
            $dropped->delete();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows  description, hsn_sac, unit_id, quantity, rate, discount_percent, tax_rate_id
     */
    private function writeDirectLines(VendorBill $bill, array $rows): void
    {
        VendorBillItem::query()->where('vendor_bill_id', $bill->id)->get()->each->delete();

        $sort = 0;
        foreach (array_values($rows) as $index => $row) {
            $key = "items.{$index}";
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(["{$key}.description" => 'Describe the line.']);
            }
            $unit = Unit::query()->whereKey((int) ($row['unit_id'] ?? 0))->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(["{$key}.unit_id" => 'Choose an active unit.']);
            $qty = $this->quantity($row['quantity'] ?? null, "{$key}.quantity");
            $rate = $this->amount($row['rate'] ?? null, "{$key}.rate", Decimal::RATE_SCALE, required: true);
            $discount = $this->percent($row['discount_percent'] ?? null, "{$key}.discount_percent");
            $tax = null;
            if (filled($row['tax_rate_id'] ?? null)) {
                $tax = TaxRate::query()->whereKey((int) $row['tax_rate_id'])->where('is_active', true)->first()
                    ?? throw ValidationException::withMessages(["{$key}.tax_rate_id" => 'Choose an active GST rate.']);
            }
            $line = $this->gst->line($qty->toQuantity(), $rate->toRate(), $discount->toString(), $tax, $bill->tax_type);

            (new VendorBillItem)->forceFill([
                'vendor_bill_id' => $bill->id,
                'description' => mb_substr($description, 0, 255),
                'hsn_sac' => $row['hsn_sac'] ?? null,
                'unit_id' => $unit->id,
                'quantity' => $qty->toQuantity(),
                'rate' => $rate->toRate(),
                'discount_percent' => $discount->round(Decimal::PERCENT_SCALE)->toString(),
                'tax_rate_id' => $tax?->id,
                'sort_order' => $sort++,
                ...$line,
            ])->save();
        }
        if ($sort === 0) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }
    }

    private function recalculate(VendorBill $bill, Decimal $tdsPercent): void
    {
        $items = VendorBillItem::query()->where('vendor_bill_id', $bill->id)->get();
        $subtotal = Decimal::sum($items->pluck('taxable_amount')->all());
        $cgst = Decimal::sum($items->pluck('cgst_amount')->all());
        $sgst = Decimal::sum($items->pluck('sgst_amount')->all());
        $igst = Decimal::sum($items->pluck('igst_amount')->all());
        $total = $subtotal->plus($cgst)->plus($sgst)->plus($igst);
        $tds = $subtotal->percentOf($tdsPercent)->round(2);

        $bill->forceFill([
            'subtotal' => $subtotal->toMoney(),
            'cgst_amount' => $cgst->toMoney(),
            'sgst_amount' => $sgst->toMoney(),
            'igst_amount' => $igst->toMoney(),
            'total_amount' => $total->toMoney(),
            'tds_percent' => $tdsPercent->round(Decimal::PERCENT_SCALE)->toString(),
            'tds_amount' => $tds->toMoney(),
            'net_payable' => $total->minus($tds)->toMoney(),
        ])->save();
    }

    private function assertPoBillable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, self::BILLABLE_PO, true)) {
            throw ValidationException::withMessages(['purchase_order_id' => "Bills can only be entered against an approved purchase order (this one is {$order->status->label()})."]);
        }
    }

    private function assertInvoiceUnique(int $vendorId, string $invoiceNo, ?int $exceptId): void
    {
        $clash = VendorBill::query()->where('vendor_id', $vendorId)->where('vendor_invoice_no', $invoiceNo)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->value('bill_number');
        if ($clash !== null) {
            throw ValidationException::withMessages(['vendor_invoice_no' => "This vendor invoice number is already entered as {$clash}."]);
        }
    }

    /**
     * 3-way match: per GRN line, this bill + the vendor's other bills (submitted / approved, plus
     * with includePending nothing else) ≤ accepted quantity.
     */
    private function assertGrnQuantities(VendorBill $bill, bool $includePending): void
    {
        $items = VendorBillItem::query()->where('vendor_bill_id', $bill->id)->get();
        $ids = $items->pluck('grn_item_id')->filter()->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['items' => 'Bill a quantity on at least one GRN line.']);
        }
        $statuses = $includePending ? [VendorBillStatus::Submitted, ...VendorBillStatus::approvedStates()] : VendorBillStatus::approvedStates();
        $billed = $this->billedQuantities($ids, $statuses, $bill->id);
        $grnItems = GrnItem::query()->whereIn('id', $ids)->with('grn:id,grn_number,status')->get()->keyBy('id');

        foreach ($items as $item) {
            $grnItem = $grnItems->get($item->grn_item_id);
            if ($grnItem === null || $grnItem->grn?->status !== GrnStatus::Approved) {
                throw ValidationException::withMessages(['items' => "The GRN of \"{$item->description}\" is no longer approved."]);
            }
            $other = $billed[$item->grn_item_id] ?? Decimal::zero();
            $balance = Decimal::of($grnItem->accepted_qty)->minus($other);
            if (Decimal::of($item->quantity)->greaterThan($balance)) {
                throw ValidationException::withMessages(['items' => "\"{$item->description}\" ({$grnItem->grn->grn_number}): accepted {$grnItem->accepted_qty}, already billed {$other->toQuantity()}, this bill {$item->quantity}. At most {$balance->toQuantity()} can be billed."]);
            }
        }
    }

    /**
     * @return Collection<int, GrnItem>
     */
    private function approvedGrnItems(PurchaseOrder $order): Collection
    {
        return GrnItem::query()
            ->whereHas('grn', fn ($q) => $q->where('purchase_order_id', $order->id)->where('status', GrnStatus::Approved))
            ->with(['grn:id,grn_number,receipt_date', 'purchaseOrderItem', 'unit:id,symbol'])
            ->orderBy('grn_id')->orderBy('id')
            ->get();
    }

    /**
     * Billed quantity per GRN line on bills in the given statuses.
     *
     * @param  list<int>  $grnItemIds
     * @param  list<VendorBillStatus>  $statuses
     * @return array<int, Decimal>
     */
    private function billedQuantities(array $grnItemIds, array $statuses, ?int $exceptBillId = null): array
    {
        if ($grnItemIds === []) {
            return [];
        }
        $rows = VendorBillItem::query()->whereIn('grn_item_id', $grnItemIds)
            ->whereHas('bill', fn ($q) => $q->whereNull('deleted_at')->whereIn('status', $statuses)
                ->when($exceptBillId, fn ($w) => $w->whereKeyNot($exceptBillId)))
            ->get(['grn_item_id', 'quantity']);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->grn_item_id] = ($totals[$row->grn_item_id] ?? Decimal::zero())->plus($row->quantity);
        }

        return $totals;
    }
}

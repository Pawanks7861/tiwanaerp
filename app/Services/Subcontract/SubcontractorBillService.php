<?php

namespace App\Services\Subcontract;

use App\Enums\CostHead;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\SubcontractorBillItem;
use App\Models\Subcontract\WorkOrder;
use App\Models\Subcontract\WorkOrderItem;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Measured subcontractor bills: draft → submitted (engine: PM → Director) → certified.
 *
 * Per line: previous = quantity certified on earlier certified bills, cumulative = previous +
 * certified this bill ≤ work order quantity. Amounts: gross = Σ round(certified × rate, 2);
 * tax, retention and TDS = round(gross × %, 2) using the work order's percentages; advance
 * recovery is entered (cumulative recoveries ≤ the work order advance, and ≤ gross);
 * net = gross + tax − retention − advance recovery − TDS − other deductions (≥ 0).
 *
 * Certification posts each line's certified amount (excluding GST) as 'subcontract' project
 * cost. Payments (Phase 7) settle the bill and post nothing.
 */
class SubcontractorBillService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProjectCostLedgerService $costs,
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): SubcontractorBill
    {
        return DB::transaction(function () use ($project, $data) {
            $order = WorkOrder::query()->where('project_id', $project->id)
                ->whereKey((int) ($data['work_order_id'] ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['work_order_id' => 'Choose a work order of this project.']);
            if (! $order->status->isBillable()) {
                throw ValidationException::withMessages(['work_order_id' => "Bills can only be raised against an approved work order (this one is {$order->status->label()})."]);
            }

            $bill = new SubcontractorBill;
            $bill->fill($this->header($data));
            $bill->forceFill([
                'project_id' => $project->id,
                'work_order_id' => $order->id,
                'subcontractor_id' => $order->subcontractor_id,
                'bill_number' => $this->numbers->next('subcontractor_bill', $project),
                'status' => SubcontractorBillStatus::Draft,
                'tax_percent' => $order->tax_percent,
                'retention_percent' => $order->retention_percent,
                'tds_percent' => $order->tds_percent,
            ])->save();

            $this->replaceLines($bill, $order, $data['items'] ?? []);
            $this->recalculate($bill, $order, $data);

            return $bill;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SubcontractorBill $bill, array $data): SubcontractorBill
    {
        return DB::transaction(function () use ($bill, $data) {
            $order = WorkOrder::query()->whereKey($bill->work_order_id)->lockForUpdate()->firstOrFail();
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $locked->fill($this->header($data));
            $locked->forceFill([
                'tax_percent' => $order->tax_percent,
                'retention_percent' => $order->retention_percent,
                'tds_percent' => $order->tds_percent,
            ])->save();
            $this->replaceLines($locked, $order, $data['items'] ?? []);
            $this->recalculate($locked, $order, $data);

            return $locked;
        });
    }

    public function delete(SubcontractorBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if ($locked->revision > 0) {
                throw ValidationException::withMessages(['bill' => 'A bill that was certified before cannot be deleted; keep it as a draft or resubmit it.']);
            }

            $locked->items()->get()->each->delete();
            $locked->delete();
        });
    }

    public function submit(SubcontractorBill $bill, User $user): void
    {
        DB::transaction(function () use ($bill, $user) {
            $order = WorkOrder::query()->whereKey($bill->work_order_id)->lockForUpdate()->firstOrFail();
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if (! $order->status->isBillable()) {
                throw ValidationException::withMessages(['bill' => "The work order is {$order->status->label()}; no further bills can be submitted."]);
            }

            $this->refreshPrevious($locked, includePending: true);
            $this->recalculate($locked, $order, $locked->only(['advance_recovery', 'other_deductions']));
            if (Decimal::of($locked->gross_amount)->isZero()) {
                throw ValidationException::withMessages(['bill' => 'Certify a quantity on at least one line before submitting.']);
            }

            $this->approvals->submit($locked, $user);
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Certifier's adjustment of a submitted bill: certified quantities and deductions.
     *
     * @param  array<string, mixed>  $data  items[] (id, certified_qty), advance_recovery, other_deductions
     */
    public function adjust(SubcontractorBill $bill, array $data): void
    {
        DB::transaction(function () use ($bill, $data) {
            $order = WorkOrder::query()->whereKey($bill->work_order_id)->lockForUpdate()->firstOrFail();
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SubcontractorBillStatus::Submitted) {
                throw ValidationException::withMessages(['bill' => 'Only a submitted bill can be adjusted by the certifier.']);
            }

            $items = $locked->items()->get()->keyBy('id');
            foreach (array_values($data['items'] ?? []) as $index => $row) {
                $item = $items->get((int) ($row['id'] ?? 0))
                    ?? throw ValidationException::withMessages(["items.{$index}.id" => 'This line does not belong to the bill.']);
                $certified = $this->quantity($row['certified_qty'] ?? null, "items.{$index}.certified_qty", allowZero: true);
                if ($certified->greaterThan($item->claimed_qty)) {
                    throw ValidationException::withMessages(["items.{$index}.certified_qty" => 'The certified quantity cannot exceed the claimed quantity.']);
                }
                $item->forceFill([
                    'certified_qty' => $certified->toQuantity(),
                    'cumulative_qty' => Decimal::of($item->previous_qty)->plus($certified)->toQuantity(),
                ])->save();
            }

            $this->refreshPrevious($locked, includePending: true);
            $this->recalculate($locked, $order, $data);
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the engine's transaction): re-checks quantities and the advance under
     * the work order lock, posts the subcontract cost per line and refreshes the work order's
     * certified quantities. The final approver needs subcontract.certify_bill. Idempotent.
     */
    public function certify(SubcontractorBill $bill, ?int $approverId): void
    {
        DB::transaction(function () use ($bill, $approverId) {
            $order = WorkOrder::query()->whereKey($bill->work_order_id)->lockForUpdate()->firstOrFail();
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($locked->isCertified()) {
                return;
            }
            if ($locked->status !== SubcontractorBillStatus::Submitted) {
                throw ValidationException::withMessages(['bill' => 'Only a submitted bill can be certified.']);
            }
            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, 'subcontract.certify_bill')) {
                throw ValidationException::withMessages(['approval' => 'Certifying a subcontractor bill needs the subcontract.certify_bill permission.']);
            }
            if (! $order->status->isBillable()) {
                throw ValidationException::withMessages(['bill' => "The work order is {$order->status->label()}; the bill cannot be certified."]);
            }

            $this->refreshPrevious($locked, includePending: false);
            $this->recalculate($locked, $order, $locked->only(['advance_recovery', 'other_deductions']));

            $workItems = WorkOrderItem::query()->where('work_order_id', $order->id)->get()->keyBy('id');
            foreach ($locked->items()->get() as $item) {
                $amount = Decimal::of($item->amount);
                if ($amount->isZero()) {
                    continue;
                }
                $workItem = $workItems->get($item->work_order_item_id);
                $this->costs->post(
                    source: $item,
                    projectId: $locked->project_id,
                    head: CostHead::Subcontract,
                    amount: $amount,
                    date: $locked->bill_date->toDateString(),
                    boqItemId: $workItem?->boq_item_id,
                    boqLineUid: $workItem?->boq_line_uid,
                    taskId: $workItem?->task_id,
                    remarks: "{$locked->bill_number} ({$order->wo_number})",
                    userId: $approverId,
                );
            }

            $locked->forceFill([
                'status' => SubcontractorBillStatus::Certified,
                'certified_by' => $approverId,
                'certified_at' => now(),
            ])->save();

            $this->refreshCertifiedQty($order);
            $this->workOrders->markInProgress($order);
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Correction of a certified (unpaid) bill: reverses its cost rows, returns it to draft
     * (revision + 1) and recomputes the work order's certified quantities. Only the latest active
     * bill of the work order can be reversed, so later bills' "previous" stays true.
     */
    public function reverse(SubcontractorBill $bill, User $user, string $reason): void
    {
        DB::transaction(function () use ($bill, $user, $reason) {
            $order = WorkOrder::query()->whereKey($bill->work_order_id)->lockForUpdate()->firstOrFail();
            $locked = SubcontractorBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SubcontractorBillStatus::Certified) {
                throw ValidationException::withMessages(['bill' => 'Only a certified, unpaid bill can be reversed.']);
            }
            $later = SubcontractorBill::query()->where('work_order_id', $order->id)->where('id', '>', $locked->id)
                ->whereIn('status', [SubcontractorBillStatus::Submitted, ...SubcontractorBillStatus::certifiedStates()])->exists();
            if ($later) {
                throw ValidationException::withMessages(['bill' => 'A later bill of this work order is submitted or certified. Reverse that bill first.']);
            }

            foreach ($locked->items()->get() as $item) {
                $this->costs->reverseActive($item, CostHead::Subcontract, "Reversed {$locked->bill_number}: {$reason}", $user->id);
            }

            $locked->forceFill([
                'status' => SubcontractorBillStatus::Draft,
                'revision' => $locked->revision + 1,
                'certified_by' => null,
                'certified_at' => null,
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ])->save();

            $this->refreshCertifiedQty($order);
            $bill->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Work order lines with their billing position (for the bill form).
     *
     * @return list<array<string, mixed>>
     */
    public function billableLines(WorkOrder $order, ?SubcontractorBill $except = null): array
    {
        $items = $order->items()->with('unit:id,symbol')->get();
        $pending = $this->quantities($items->modelKeys(), [SubcontractorBillStatus::Submitted], $except?->id);
        $certified = $this->quantities($items->modelKeys(), SubcontractorBillStatus::certifiedStates(), $except?->id);

        return $items->map(fn (WorkOrderItem $i) => [
            'id' => $i->id,
            'description' => $i->description,
            'unit' => $i->unit?->symbol,
            'quantity' => $i->quantity,
            'rate' => $i->rate,
            'previous_qty' => ($certified[$i->id] ?? Decimal::zero())->toQuantity(),
            'pending_qty' => ($pending[$i->id] ?? Decimal::zero())->toQuantity(),
            'balance_qty' => Decimal::of($i->quantity)->minus($certified[$i->id] ?? '0')->minus($pending[$i->id] ?? '0')->toQuantity(),
        ])->all();
    }

    /** Advance still recoverable on the work order (excluding the given bill). */
    public function advanceBalance(WorkOrder $order, ?int $exceptBillId = null): Decimal
    {
        $recovered = Decimal::sum(SubcontractorBill::query()->where('work_order_id', $order->id)
            ->when($exceptBillId, fn ($q) => $q->whereKeyNot($exceptBillId))
            ->whereIn('status', [SubcontractorBillStatus::Submitted, ...SubcontractorBillStatus::certifiedStates()])
            ->pluck('advance_recovery')->all());

        return Decimal::of($order->advance_amount)->minus($recovered);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        if ($data['period_to'] < $data['period_from']) {
            throw ValidationException::withMessages(['period_to' => 'The period must end on or after its start.']);
        }

        return [
            'bill_date' => $data['bill_date'],
            'subcontractor_invoice_no' => $data['subcontractor_invoice_no'] ?? null,
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  work_order_item_id, claimed_qty, certified_qty
     */
    private function replaceLines(SubcontractorBill $bill, WorkOrder $order, array $rows): void
    {
        $workItems = WorkOrderItem::query()->where('work_order_id', $order->id)->get()->keyBy('id');
        $existing = SubcontractorBillItem::query()->where('subcontractor_bill_id', $bill->id)->get()->keyBy('work_order_item_id');

        $seen = [];
        $kept = [];
        foreach (array_values($rows) as $index => $row) {
            $key = "items.{$index}";
            $workItem = $workItems->get((int) ($row['work_order_item_id'] ?? 0))
                ?? throw ValidationException::withMessages(["{$key}.work_order_item_id" => 'This line is not on the work order.']);
            if (isset($seen[$workItem->id])) {
                throw ValidationException::withMessages(["{$key}.work_order_item_id" => 'Each work order line can appear once per bill.']);
            }
            $seen[$workItem->id] = true;

            $claimed = $this->quantity($row['claimed_qty'] ?? null, "{$key}.claimed_qty", allowZero: true);
            $certified = blank($row['certified_qty'] ?? null) ? $claimed : $this->quantity($row['certified_qty'], "{$key}.certified_qty", allowZero: true);
            if ($certified->greaterThan($claimed)) {
                throw ValidationException::withMessages(["{$key}.certified_qty" => 'The certified quantity cannot exceed the claimed quantity.']);
            }
            if ($claimed->isZero()) {
                continue;
            }

            $kept[$workItem->id] = true;
            ($existing->get($workItem->id) ?? new SubcontractorBillItem)->forceFill([
                'subcontractor_bill_id' => $bill->id,
                'work_order_item_id' => $workItem->id,
                'wo_qty' => $workItem->quantity,
                'previous_qty' => '0',
                'claimed_qty' => $claimed->toQuantity(),
                'certified_qty' => $certified->toQuantity(),
                'cumulative_qty' => $certified->toQuantity(),
                'rate' => $workItem->rate,
                'amount' => '0.00',
            ])->save();
        }

        if ($kept === []) {
            throw ValidationException::withMessages(['items' => 'Claim a quantity on at least one work order line.']);
        }

        // Lines dropped from a reopened bill keep their ledger history: zeroed, not deleted.
        foreach ($existing->reject(fn (SubcontractorBillItem $item) => isset($kept[$item->work_order_item_id])) as $item) {
            if ($this->costs->entryFor($item, CostHead::Subcontract) !== null) {
                $item->forceFill(['claimed_qty' => '0', 'certified_qty' => '0', 'cumulative_qty' => $item->previous_qty, 'amount' => '0.00'])->save();
            } else {
                $item->delete();
            }
        }

        $this->refreshPrevious($bill, includePending: false);
    }

    /**
     * previous = certified on other certified bills; cumulative = previous + certified ≤ WO qty.
     * With includePending, other submitted bills also count against the work order quantity.
     */
    private function refreshPrevious(SubcontractorBill $bill, bool $includePending): void
    {
        $items = $bill->items()->get();
        $ids = $items->pluck('work_order_item_id')->all();
        $certified = $this->quantities($ids, SubcontractorBillStatus::certifiedStates(), $bill->id);
        $pending = $includePending ? $this->quantities($ids, [SubcontractorBillStatus::Submitted], $bill->id) : [];
        $descriptions = WorkOrderItem::query()->whereIn('id', $ids)->pluck('description', 'id');

        foreach ($items as $item) {
            $previous = $certified[$item->work_order_item_id] ?? Decimal::zero();
            $cumulative = $previous->plus($item->certified_qty);
            $committed = $cumulative->plus($pending[$item->work_order_item_id] ?? '0');

            if ($committed->greaterThan($item->wo_qty)) {
                $balance = Decimal::of($item->wo_qty)->minus($previous)->minus($pending[$item->work_order_item_id] ?? '0');
                throw ValidationException::withMessages(['items' => "Over-certification on \"{$descriptions[$item->work_order_item_id]}\": work order quantity {$item->wo_qty}, already certified {$previous->toQuantity()}"
                    .($includePending ? ', pending '.($pending[$item->work_order_item_id] ?? Decimal::zero())->toQuantity() : '')
                    .", this bill {$item->certified_qty}. Balance {$balance->toQuantity()}."]);
            }

            $item->forceFill([
                'previous_qty' => $previous->toQuantity(),
                'cumulative_qty' => $cumulative->toQuantity(),
                'amount' => Decimal::of($item->certified_qty)->times($item->rate)->round(2)->toMoney(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data  advance_recovery, other_deductions
     */
    private function recalculate(SubcontractorBill $bill, WorkOrder $order, array $data): void
    {
        $gross = Decimal::sum($bill->items()->pluck('amount')->all());
        $tax = $gross->percentOf($bill->tax_percent)->round(2);
        $retention = $gross->percentOf($bill->retention_percent)->round(2);
        $tds = $gross->percentOf($bill->tds_percent)->round(2);
        $advance = $this->amount($data['advance_recovery'] ?? null, 'advance_recovery');
        $other = $this->amount($data['other_deductions'] ?? null, 'other_deductions');

        $balance = $this->advanceBalance($order, $bill->id);
        if ($advance->greaterThan($balance)) {
            throw ValidationException::withMessages(['advance_recovery' => 'The advance recovery cannot exceed the unrecovered work order advance ('.$balance->toMoney().').']);
        }
        if ($advance->greaterThan($gross)) {
            throw ValidationException::withMessages(['advance_recovery' => 'The advance recovery cannot exceed the gross amount of this bill.']);
        }

        $net = $gross->plus($tax)->minus($retention)->minus($advance)->minus($tds)->minus($other);
        if ($net->isNegative()) {
            throw ValidationException::withMessages(['other_deductions' => 'Deductions cannot exceed the bill amount (net payable would be '.$net->toMoney().').']);
        }

        $bill->forceFill([
            'gross_amount' => $gross->toMoney(),
            'tax_amount' => $tax->toMoney(),
            'retention_amount' => $retention->toMoney(),
            'advance_recovery' => $advance->toMoney(),
            'tds_amount' => $tds->toMoney(),
            'other_deductions' => $other->toMoney(),
            'net_payable' => $net->toMoney(),
        ])->save();
    }

    /** Rebuild every work order line's certified_qty from certified bills (never incremented). */
    private function refreshCertifiedQty(WorkOrder $order): void
    {
        $items = WorkOrderItem::query()->where('work_order_id', $order->id)->get();
        $certified = $this->quantities($items->modelKeys(), SubcontractorBillStatus::certifiedStates());

        foreach ($items as $item) {
            $qty = ($certified[$item->id] ?? Decimal::zero())->toQuantity();
            if (! Decimal::of($item->certified_qty)->equals($qty)) {
                $item->forceFill(['certified_qty' => $qty])->save();
            }
        }
    }

    /**
     * Certified quantity per work order line on bills in the given statuses.
     *
     * @param  list<int>  $workItemIds
     * @param  list<SubcontractorBillStatus>  $statuses
     * @return array<int, Decimal>
     */
    private function quantities(array $workItemIds, array $statuses, ?int $exceptBillId = null): array
    {
        if ($workItemIds === []) {
            return [];
        }

        /** @var Collection<int, SubcontractorBillItem> $rows */
        $rows = SubcontractorBillItem::query()
            ->whereIn('work_order_item_id', $workItemIds)
            ->when($exceptBillId, fn ($q) => $q->where('subcontractor_bill_id', '!=', $exceptBillId))
            ->whereHas('bill', fn ($q) => $q->whereIn('status', $statuses))
            ->get(['work_order_item_id', 'certified_qty']);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->work_order_item_id] = ($totals[$row->work_order_item_id] ?? Decimal::zero())->plus($row->certified_qty);
        }

        return $totals;
    }
}

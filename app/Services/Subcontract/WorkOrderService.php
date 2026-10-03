<?php

namespace App\Services\Subcontract;

use App\Enums\Subcontract\MilestoneStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Models\Subcontract\WorkOrderItem;
use App\Models\Subcontract\WorkOrderMilestone;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Work orders: draft → submitted (engine: PM → Director) → approved → in progress (first
 * certified bill) → completed → closed; or rejected / cancelled. Line amount = qty × rate
 * (2 dp), tax = subtotal × tax % (2 dp), total = subtotal + tax. An approved order is locked:
 * scope changes are made by cancelling and issuing a new order (no amendment in Phase 6).
 */
class WorkOrderService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): WorkOrder
    {
        return DB::transaction(function () use ($project, $data) {
            $order = new WorkOrder;
            $order->fill($this->header($data));
            $order->forceFill([
                'project_id' => $project->id,
                'wo_number' => $this->numbers->next('work_order', $project),
                'status' => WorkOrderStatus::Draft,
                'tax_percent' => $this->taxPercent($data['tax_rate_id'] ?? null),
            ])->save();

            $this->replaceLines($order, $project, $data);

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkOrder $order, array $data): WorkOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $project = Project::query()->findOrFail($locked->project_id);

            $locked->fill($this->header($data));
            $locked->forceFill(['tax_percent' => $this->taxPercent($data['tax_rate_id'] ?? null)])->save();
            $this->replaceLines($locked, $project, $data);

            return $locked;
        });
    }

    public function delete(WorkOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WorkOrderStatus::Draft) {
                throw ValidationException::withMessages(['work_order' => 'Only a draft work order can be deleted.']);
            }
            $locked->delete();
        });
    }

    public function submit(WorkOrder $order, User $user): void
    {
        DB::transaction(function () use ($order, $user) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['work_order' => 'Add at least one item before submitting.']);
            }

            $this->approvals->submit($locked, $user);
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the engine's transaction). The final approver needs
     * subcontract.approve_wo. Idempotent.
     */
    public function approve(WorkOrder $order, ?int $approverId): void
    {
        DB::transaction(function () use ($order, $approverId) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === WorkOrderStatus::Approved) {
                return;
            }
            if ($locked->status !== WorkOrderStatus::Submitted) {
                throw ValidationException::withMessages(['work_order' => 'Only a submitted work order can be approved.']);
            }

            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, 'subcontract.approve_wo')) {
                throw ValidationException::withMessages(['approval' => 'Approving a work order needs the subcontract.approve_wo permission.']);
            }

            $locked->forceFill([
                'status' => WorkOrderStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function complete(WorkOrder $order, User $user): void
    {
        $this->transition($order, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], WorkOrderStatus::Completed, $user);
    }

    /** Close: no further bills. Pending (draft / submitted) bills must be finished or deleted first. */
    public function close(WorkOrder $order, User $user): void
    {
        DB::transaction(function () use ($order, $user) {
            $this->assertNoBills($order, [SubcontractorBillStatus::Draft, SubcontractorBillStatus::Submitted, SubcontractorBillStatus::Rejected],
                'Finish or delete the pending bills of this work order before closing it.');
            $this->transition($order, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress, WorkOrderStatus::Completed], WorkOrderStatus::Closed, $user, [
                'closed_by' => $user->id,
                'closed_at' => now(),
            ]);
        });
    }

    /** Cancel an approved order nothing has been billed against. */
    public function cancel(WorkOrder $order, User $user, string $reason): void
    {
        DB::transaction(function () use ($order, $user, $reason) {
            $this->assertNoBills($order, SubcontractorBillStatus::cases(),
                'Bills exist against this work order; it cannot be cancelled. Close it instead.');
            $this->transition($order, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], WorkOrderStatus::Cancelled, $user, [
                'cancellation_reason' => $reason,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ]);
        });
    }

    /** First certified bill moves an approved order to in progress. */
    public function markInProgress(WorkOrder $locked): void
    {
        if ($locked->status === WorkOrderStatus::Approved) {
            $locked->forceFill(['status' => WorkOrderStatus::InProgress])->save();
        }
    }

    /**
     * Record milestone achievement on an approved order.
     *
     * @param  list<array<string, mixed>>  $rows  id, status
     */
    public function updateMilestones(WorkOrder $order, array $rows): void
    {
        DB::transaction(function () use ($order, $rows) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isBillable()) {
                throw ValidationException::withMessages(['milestones' => 'Milestones can be updated while the work order is approved, in progress or completed.']);
            }

            $milestones = $locked->milestones()->get()->keyBy('id');
            foreach (array_values($rows) as $index => $row) {
                $milestone = $milestones->get((int) ($row['id'] ?? 0))
                    ?? throw ValidationException::withMessages(["milestones.{$index}.id" => 'This milestone does not belong to the work order.']);
                $status = MilestoneStatus::tryFrom((string) ($row['status'] ?? ''))
                    ?? throw ValidationException::withMessages(["milestones.{$index}.status" => 'Choose pending or achieved.']);

                if ($milestone->status !== $status) {
                    $milestone->forceFill([
                        'status' => $status,
                        'achieved_at' => $status === MilestoneStatus::Achieved ? now() : null,
                    ])->save();
                }
            }
        });
    }

    /**
     * @param  list<WorkOrderStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(WorkOrder $order, array $from, WorkOrderStatus $to, User $user, array $extra = []): void
    {
        DB::transaction(function () use ($order, $from, $to, $extra) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $to) {
                return;
            }
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['work_order' => "A {$locked->status->label()} work order cannot be marked {$to->label()}."]);
            }

            $locked->forceFill(['status' => $to, ...$extra])->save();
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  list<SubcontractorBillStatus>  $statuses
     */
    private function assertNoBills(WorkOrder $order, array $statuses, string $message): void
    {
        WorkOrder::query()->whereKey($order->id)->lockForUpdate()->first();
        if (SubcontractorBill::query()->where('work_order_id', $order->id)->whereIn('status', $statuses)->exists()) {
            throw ValidationException::withMessages(['work_order' => $message]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $subcontractor = Subcontractor::query()->active()->whereKey((int) ($data['subcontractor_id'] ?? 0))->first()
            ?? throw ValidationException::withMessages(['subcontractor_id' => 'Choose an active subcontractor.']);

        if (! blank($data['start_date'] ?? null) && ! blank($data['end_date'] ?? null) && $data['end_date'] < $data['start_date']) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }

        return [
            'subcontractor_id' => $subcontractor->id,
            'wo_date' => $data['wo_date'],
            'scope' => $data['scope'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'retention_percent' => $this->percent($data['retention_percent'] ?? null, 'retention_percent')->toString(),
            'advance_amount' => $this->amount($data['advance_amount'] ?? null, 'advance_amount')->toMoney(),
            'tax_rate_id' => blank($data['tax_rate_id'] ?? null) ? null : (int) $data['tax_rate_id'],
            'tds_percent' => $this->percent($data['tds_percent'] ?? null, 'tds_percent')->toString(),
            'terms' => $data['terms'] ?? null,
        ];
    }

    private function taxPercent(mixed $taxRateId): string
    {
        if (blank($taxRateId)) {
            return '0';
        }

        $rate = TaxRate::query()->active()->whereKey((int) $taxRateId)->value('rate');

        return $rate !== null ? (string) $rate : throw ValidationException::withMessages(['tax_rate_id' => 'Choose an active tax rate.']);
    }

    /**
     * @param  array<string, mixed>  $data  items[], milestones[]
     */
    private function replaceLines(WorkOrder $order, Project $project, array $data): void
    {
        $rows = array_values($data['items'] ?? []);
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }

        WorkOrderItem::query()->where('work_order_id', $order->id)->get()->each->delete();
        WorkOrderMilestone::query()->where('work_order_id', $order->id)->get()->each->delete();

        $subtotal = Decimal::zero();
        foreach ($rows as $index => $row) {
            $key = "items.{$index}";
            $boqItem = $this->boqItem($project, $row['boq_item_id'] ?? null, "{$key}.boq_item_id");
            $quantity = $this->quantity($row['quantity'] ?? null, "{$key}.quantity");
            $rate = $this->amount($row['rate'] ?? null, "{$key}.rate", Decimal::RATE_SCALE);
            $unitId = blank($row['unit_id'] ?? null) ? $boqItem?->unit_id : (int) $row['unit_id'];
            if ($unitId === null || ! Unit::query()->whereKey($unitId)->exists()) {
                throw ValidationException::withMessages(["{$key}.unit_id" => 'Choose a unit.']);
            }
            $description = trim((string) ($row['description'] ?? '')) ?: $boqItem?->name;
            if (blank($description)) {
                throw ValidationException::withMessages(["{$key}.description" => 'Describe the work.']);
            }

            $amount = $quantity->times($rate)->round(2);
            $subtotal = $subtotal->plus($amount);

            (new WorkOrderItem)->forceFill([
                'work_order_id' => $order->id,
                'boq_item_id' => $boqItem?->id,
                'boq_line_uid' => $boqItem?->line_uid,
                'task_id' => $this->task($project, $row['task_id'] ?? null, "{$key}.task_id")?->id,
                'description' => mb_substr($description, 0, 500),
                'unit_id' => $unitId,
                'quantity' => $quantity->toQuantity(),
                'rate' => $rate->toRate(),
                'amount' => $amount->toMoney(),
                'certified_qty' => '0',
                'sort_order' => $index,
            ])->save();
        }

        $milestoneTotal = Decimal::zero();
        foreach (array_values($data['milestones'] ?? []) as $index => $row) {
            $percent = $this->percent($row['amount_percent'] ?? null, "milestones.{$index}.amount_percent");
            $milestoneTotal = $milestoneTotal->plus($percent);
            if (blank($row['name'] ?? null)) {
                throw ValidationException::withMessages(["milestones.{$index}.name" => 'Name the milestone.']);
            }

            (new WorkOrderMilestone)->forceFill([
                'work_order_id' => $order->id,
                'name' => mb_substr((string) $row['name'], 0, 200),
                'due_date' => $row['due_date'] ?? null,
                'amount_percent' => $percent->toString(),
                'status' => MilestoneStatus::Pending,
                'sort_order' => $index,
            ])->save();
        }
        if ($milestoneTotal->greaterThan(100)) {
            throw ValidationException::withMessages(['milestones' => 'Milestones cannot total more than 100% ('.$milestoneTotal->round(4)->toString().'%).']);
        }

        $tax = $subtotal->percentOf($order->tax_percent)->round(2);
        $total = $subtotal->plus($tax);
        if (Decimal::of($order->advance_amount)->greaterThan($total)) {
            throw ValidationException::withMessages(['advance_amount' => 'The advance cannot exceed the work order value ('.$total->toMoney().').']);
        }

        $order->forceFill([
            'subtotal' => $subtotal->toMoney(),
            'tax_amount' => $tax->toMoney(),
            'total_value' => $total->toMoney(),
        ])->save();
    }
}

<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\AdjustmentReason;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\Concerns\ResolvesInventoryLines;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Stock adjustments. Each line snapshots the book quantity (system_qty) when saved; the server
 * computes difference = physical − system. Approval (inventory.approve_adjustment, not the
 * submitter) re-reads the balance under lock and refuses to post if it moved since the count.
 *
 * Valuation: losses leave at the weighted average. Gains come in at the weighted average, or at
 * the entered unit cost when the store holds none of the item. Opening stock (reason 'opening')
 * is only allowed for an item that has never moved in that store and always needs a unit cost.
 */
class StockAdjustmentService
{
    use ResolvesInventoryLines;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
        private readonly InventoryScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($project, $data) {
            $adjustment = new StockAdjustment($this->header($project, $data));
            $adjustment->forceFill([
                'project_id' => $project->id,
                'adjustment_number' => $this->numbers->next('stock_adjustment', $project),
                'status' => InventoryDocumentStatus::Draft,
            ])->save();

            $this->replaceLines($adjustment, $data['items'] ?? []);

            return $adjustment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data) {
            $adjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $adjustment->assertEditable();

            $adjustment->fill($this->header(Project::query()->findOrFail($adjustment->project_id), $data))->save();
            $this->replaceLines($adjustment, $data['items'] ?? []);

            return $adjustment;
        });
    }

    public function delete(StockAdjustment $adjustment): void
    {
        $adjustment->assertEditable();
        $adjustment->delete();
    }

    public function submit(StockAdjustment $adjustment, User $user): void
    {
        DB::transaction(function () use ($adjustment, $user) {
            $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $items = $locked->items()->with('material:id,name')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['adjustment' => 'Add at least one line before submitting.']);
            }
            foreach ($items as $item) {
                $this->assertSnapshotCurrent($locked, $item, $this->ledger->balance($locked->warehouse_id, $item->material_id)['quantity']);
            }

            $locked->forceFill([
                'status' => InventoryDocumentStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'rejection_reason' => null,
            ])->save();
            $locked->writeAudit('submitted');
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Post the adjustment. Idempotent: approving an approved adjustment changes nothing.
     */
    public function approve(StockAdjustment $adjustment, User $user): void
    {
        DB::transaction(function () use ($adjustment, $user) {
            $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryDocumentStatus::Approved) {
                $adjustment->setRawAttributes($locked->getAttributes(), true);

                return;
            }
            if ($locked->status !== InventoryDocumentStatus::Submitted) {
                throw ValidationException::withMessages(['adjustment' => 'Only a submitted adjustment can be approved.']);
            }
            if ((int) $locked->submitted_by === $user->id) {
                throw ValidationException::withMessages(['adjustment' => 'An adjustment must be approved by someone other than the person who submitted it.']);
            }

            $warehouse = Warehouse::query()->withTrashed()->findOrFail($locked->warehouse_id);
            $items = $locked->items()->with('material:id,name')->reorder('material_id')->get();

            foreach ($items as $item) {
                $balance = $this->ledger->lockBalance($warehouse->id, $item->material_id);
                $this->assertSnapshotCurrent($locked, $item, Decimal::of($balance->quantity));

                $difference = Decimal::of($item->difference);
                if ($locked->reason === AdjustmentReason::Opening && $this->hasMovements($warehouse->id, $item->material_id)) {
                    throw ValidationException::withMessages(['items' => "{$item->material->name} already has stock movements in {$warehouse->name}; use a count correction instead of opening stock."]);
                }

                $incoming = $difference->isPositive();
                $unitCost = null;
                if ($incoming) {
                    $unitCost = $locked->reason !== AdjustmentReason::Opening && Decimal::of($balance->quantity)->isPositive()
                        ? Decimal::of($balance->avg_cost)
                        : ($item->unit_cost !== null ? Decimal::of($item->unit_cost) : throw ValidationException::withMessages([
                            'items' => "Enter a unit cost for {$item->material->name}: {$warehouse->name} holds none of it to take an average from.",
                        ]));
                }

                $transaction = $this->ledger->post(new StockMovement(
                    source: $item,
                    type: match (true) {
                        ! $incoming => StockTxnType::AdjustmentOut,
                        $locked->reason === AdjustmentReason::Opening => StockTxnType::Opening,
                        default => StockTxnType::AdjustmentIn,
                    },
                    warehouse: $warehouse,
                    materialId: $item->material_id,
                    quantity: $difference->abs(),
                    date: $locked->adjustment_date->toDateString(),
                    projectId: $locked->project_id,
                    unitCost: $unitCost,
                    remarks: "{$locked->adjustment_number} ({$locked->reason->label()})",
                    userId: $user->id,
                ));

                $item->forceFill(['unit_cost' => $transaction->unit_cost, 'value' => $transaction->value])->save();
            }

            $locked->forceFill([
                'status' => InventoryDocumentStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();
            $locked->writeAudit('approved');
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function reject(StockAdjustment $adjustment, User $user, string $reason): void
    {
        DB::transaction(function () use ($adjustment, $user, $reason) {
            $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InventoryDocumentStatus::Submitted) {
                throw ValidationException::withMessages(['adjustment' => 'Only a submitted adjustment can be rejected.']);
            }

            $locked->forceFill(['status' => InventoryDocumentStatus::Rejected, 'rejection_reason' => $reason])->save();
            $locked->writeAudit('rejected', null, ['reason' => $reason, 'by' => $user->id]);
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Cancel a posted adjustment with reversals. Reversing a gain is refused once that stock was used.
     */
    public function cancel(StockAdjustment $adjustment, User $user, string $reason): void
    {
        DB::transaction(function () use ($adjustment, $user, $reason) {
            $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InventoryDocumentStatus::Cancelled) {
                return;
            }
            if ($locked->status !== InventoryDocumentStatus::Approved) {
                throw ValidationException::withMessages(['adjustment' => 'Only a posted adjustment can be cancelled; edit or delete it instead.']);
            }

            $remarks = "Cancelled {$locked->adjustment_number}: {$reason}";
            foreach ($locked->items()->reorder('material_id')->get() as $item) {
                $forward = StockTransaction::query()
                    ->where('source_type', $item->getMorphClass())->where('source_id', $item->id)
                    ->whereIn('txn_type', [StockTxnType::AdjustmentIn, StockTxnType::AdjustmentOut, StockTxnType::Opening])->first();
                if ($forward) {
                    $this->ledger->reverse($forward, $remarks, $user->id, 'adjustment');
                }
            }

            $locked->forceFill([
                'status' => InventoryDocumentStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function assertSnapshotCurrent(StockAdjustment $adjustment, StockAdjustmentItem $item, Decimal $bookQty): void
    {
        if (! $bookQty->equals($item->system_qty)) {
            $name = $item->material?->name ?? 'an item';
            throw ValidationException::withMessages(['items' => "The book stock of {$name} changed since it was counted (was ".Decimal::of($item->system_qty)->toQuantity()
                .", now {$bookQty->toQuantity()}). ".($adjustment->isEditable() ? 'Save the adjustment again to refresh it.' : 'Reject it so the count can be redone.')]);
        }
    }

    private function hasMovements(int $warehouseId, int $materialId): bool
    {
        return StockTransaction::query()->where('warehouse_id', $warehouseId)->where('material_id', $materialId)->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $rows  material_id, physical_qty, unit_cost, remarks
     */
    private function replaceLines(StockAdjustment $adjustment, array $rows): void
    {
        StockAdjustmentItem::query()->where('stock_adjustment_id', $adjustment->id)->get()->each->delete();

        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        $seen = [];
        foreach (array_values($rows) as $index => $row) {
            $material = $this->material($row['material_id'] ?? null, "items.{$index}.material_id");
            if (isset($seen[$material->id])) {
                throw ValidationException::withMessages(["items.{$index}.material_id" => 'Each item can only appear once.']);
            }
            $seen[$material->id] = true;

            $physical = $this->quantity($row['physical_qty'] ?? null, "items.{$index}.physical_qty", allowZero: true);
            $system = $this->ledger->balance($adjustment->warehouse_id, $material->id)['quantity'];
            $difference = $physical->minus($system);
            $unitCost = $this->unitCost($row['unit_cost'] ?? null, "items.{$index}.unit_cost");

            if ($difference->isZero()) {
                throw ValidationException::withMessages(["items.{$index}.physical_qty" => "The physical quantity equals the book stock ({$system->toQuantity()}); remove the line."]);
            }
            if ($adjustment->reason->isLossOnly() && $difference->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.physical_qty" => "{$adjustment->reason->label()} can only reduce stock (book stock {$system->toQuantity()})."]);
            }
            if ($adjustment->reason === AdjustmentReason::Opening) {
                if ($this->hasMovements($adjustment->warehouse_id, $material->id)) {
                    throw ValidationException::withMessages(["items.{$index}.material_id" => 'This item already has stock movements in the store; use a count correction.']);
                }
                if ($unitCost === null || ! $unitCost->isPositive()) {
                    throw ValidationException::withMessages(["items.{$index}.unit_cost" => 'Opening stock needs a unit cost.']);
                }
            } elseif ($difference->isPositive() && $system->isZero() && $unitCost === null) {
                throw ValidationException::withMessages(["items.{$index}.unit_cost" => 'Enter a unit cost: the store holds none of this item to take an average from.']);
            }

            (new StockAdjustmentItem)->forceFill([
                'stock_adjustment_id' => $adjustment->id,
                'material_id' => $material->id,
                'unit_id' => $material->unit_id,
                'system_qty' => $system->toQuantity(),
                'physical_qty' => $physical->toQuantity(),
                'difference' => $difference->toQuantity(),
                'unit_cost' => $difference->isPositive() ? $unitCost?->toRate() : null,
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }
    }

    private function unitCost(mixed $value, string $key): ?Decimal
    {
        if (blank($value)) {
            return null;
        }

        try {
            $cost = Decimal::of(is_scalar($value) ? (string) $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'Enter a valid unit cost.']);
        }

        if ($cost->isNegative()) {
            throw ValidationException::withMessages([$key => 'The unit cost cannot be negative.']);
        }

        return $cost->round(Decimal::RATE_SCALE);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        return [
            'warehouse_id' => $this->scope->warehouse($project, $data['warehouse_id'] ?? null)->id,
            'adjustment_date' => $data['adjustment_date'],
            'reason' => $data['reason'],
            'remarks' => $data['remarks'],
        ];
    }
}

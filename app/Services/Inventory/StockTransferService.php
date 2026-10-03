<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockTransferStatus;
use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockTransaction;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\Inventory\StockTransferReceipt;
use App\Models\Inventory\StockTransferReceiptItem;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\Concerns\ResolvesInventoryLines;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock transfers between two stores reachable from the project.
 *
 * draft → dispatched (transfer_out at the source average, cost captured per line)
 *       → partially_received → received (each receipt posts transfer_in at the captured cost)
 * A dispatched transfer with nothing received can be cancelled (transfer_out reversed); a partly
 * received one can be closed short (the undelivered rest goes back into the source store).
 * Values are split so that receipts plus any short-close always add up to the dispatched value.
 */
class StockTransferService
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
    public function create(Project $project, array $data): StockTransfer
    {
        return DB::transaction(function () use ($project, $data) {
            $transfer = new StockTransfer($this->header($project, $data));
            $transfer->forceFill([
                'project_id' => $project->id,
                'transfer_number' => $this->numbers->next('stock_transfer', $project),
                'status' => StockTransferStatus::Draft,
            ])->save();

            $this->replaceLines($transfer, $data['items'] ?? []);

            return $transfer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $transfer->assertEditable();

            $transfer->fill($this->header(Project::query()->findOrFail($transfer->project_id), $data))->save();
            $this->replaceLines($transfer, $data['items'] ?? []);

            return $transfer;
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        $transfer->assertEditable();
        $transfer->delete();
    }

    /**
     * Post transfer_out for every line. Idempotent: an already dispatched transfer is left alone.
     */
    public function dispatch(StockTransfer $transfer, User $user): void
    {
        DB::transaction(function () use ($transfer, $user) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StockTransferStatus::Draft) {
                if ($locked->dispatched_at !== null) {
                    $transfer->setRawAttributes($locked->getAttributes(), true);

                    return;
                }
                throw $locked->lockedException();
            }

            $project = Project::query()->findOrFail($locked->project_id);
            $from = $this->scope->warehouse($project, $locked->from_warehouse_id, 'from_warehouse_id');
            $this->scope->warehouse($project, $locked->to_warehouse_id, 'to_warehouse_id');

            $items = $locked->items()->reorder('material_id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['transfer' => 'Add at least one line before dispatching.']);
            }

            foreach ($items as $item) {
                $transaction = $this->ledger->post(new StockMovement(
                    source: $item,
                    type: StockTxnType::TransferOut,
                    warehouse: $from,
                    materialId: $item->material_id,
                    quantity: Decimal::of($item->quantity),
                    date: $locked->transfer_date->toDateString(),
                    projectId: $locked->project_id,
                    remarks: $locked->transfer_number,
                    userId: $user->id,
                ));

                $item->forceFill(['unit_cost' => $transaction->unit_cost, 'value' => $transaction->value])->save();
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Dispatched,
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
            ])->save();
            $locked->writeAudit('dispatched');
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Record a (partial) receipt. The idempotency key makes a retried submit of the same receipt
     * return the receipt already recorded instead of posting twice.
     *
     * @param  array<string, mixed>  $data  receipt_date, remarks, idempotency_key, items[stock_transfer_item_id, quantity]
     */
    public function receive(StockTransfer $transfer, User $user, array $data): StockTransferReceipt
    {
        return DB::transaction(function () use ($transfer, $user, $data) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            $existing = StockTransferReceipt::query()->where('stock_transfer_id', $locked->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }

            if (! $locked->status->isInTransit()) {
                throw ValidationException::withMessages(['transfer' => 'Only a dispatched transfer with goods in transit can be received.']);
            }
            if ($locked->transfer_date->toDateString() > (string) $data['receipt_date']) {
                throw ValidationException::withMessages(['receipt_date' => 'The receipt cannot be dated before the transfer.']);
            }

            $items = $locked->items()->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach (array_values($data['items'] ?? []) as $index => $row) {
                $item = $items->get((int) ($row['stock_transfer_item_id'] ?? 0))
                    ?? throw ValidationException::withMessages(["items.{$index}.stock_transfer_item_id" => 'This line is not part of the transfer.']);
                $quantity = $this->quantity($row['quantity'] ?? '0', "items.{$index}.quantity", allowZero: true);
                if ($quantity->isZero()) {
                    continue;
                }
                if (isset($lines[$item->id])) {
                    throw ValidationException::withMessages(["items.{$index}.stock_transfer_item_id" => 'Each line can only appear once.']);
                }
                if ($quantity->greaterThan($item->inTransitQty())) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => 'At most '.$item->inTransitQty()->toQuantity().' is still in transit on this line.']);
                }
                $lines[$item->id] = [$item, $quantity];
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Enter the received quantity for at least one line.']);
            }

            $receipt = new StockTransferReceipt;
            $receipt->forceFill([
                'stock_transfer_id' => $locked->id,
                'receipt_date' => $data['receipt_date'],
                'remarks' => $data['remarks'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $user->id,
            ])->save();

            $to = Warehouse::query()->withTrashed()->findOrFail($locked->to_warehouse_id);
            uasort($lines, fn ($a, $b) => $a[0]->material_id <=> $b[0]->material_id);

            foreach ($lines as [$item, $quantity]) {
                $value = $this->valueShare($item, $quantity);

                $receiptItem = new StockTransferReceiptItem;
                $receiptItem->forceFill([
                    'stock_transfer_receipt_id' => $receipt->id,
                    'stock_transfer_item_id' => $item->id,
                    'quantity' => $quantity->toQuantity(),
                    'value' => $value->toMoney(),
                ])->save();

                $this->ledger->post(new StockMovement(
                    source: $receiptItem,
                    type: StockTxnType::TransferIn,
                    warehouse: $to,
                    materialId: $item->material_id,
                    quantity: $quantity,
                    date: (string) $data['receipt_date'],
                    projectId: $locked->project_id,
                    unitCost: Decimal::of($item->unit_cost),
                    value: $value,
                    remarks: $locked->transfer_number,
                    userId: $user->id,
                ));

                $item->forceFill([
                    'received_qty' => Decimal::of($item->received_qty)->plus($quantity)->toQuantity(),
                    'received_value' => Decimal::of($item->received_value)->plus($value)->toMoney(),
                ])->save();
            }

            $complete = $items->every(fn (StockTransferItem $item) => $item->inTransitQty()->isZero());
            $locked->forceFill([
                'status' => $complete ? StockTransferStatus::Received : StockTransferStatus::PartiallyReceived,
                'completed_at' => $complete ? now() : null,
            ])->save();
            $locked->writeAudit('received', null, ['receipt_id' => $receipt->id]);
            $transfer->setRawAttributes($locked->getAttributes(), true);

            return $receipt;
        });
    }

    /**
     * Cancel a dispatched transfer before anything was received: every transfer_out is reversed,
     * putting the goods back into the source store at the dispatched cost.
     */
    public function cancel(StockTransfer $transfer, User $user, string $reason): void
    {
        DB::transaction(function () use ($transfer, $user, $reason) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === StockTransferStatus::Cancelled) {
                return;
            }
            if ($locked->status !== StockTransferStatus::Dispatched) {
                throw ValidationException::withMessages(['transfer' => $locked->status === StockTransferStatus::Draft
                    ? 'A draft transfer is deleted, not cancelled.'
                    : 'Part of this transfer has been received. Close it short instead.']);
            }

            $remarks = "Cancelled {$locked->transfer_number}: {$reason}";
            foreach ($locked->items()->reorder('material_id')->get() as $item) {
                $forward = StockTransaction::query()
                    ->where('source_type', $item->getMorphClass())->where('source_id', $item->id)
                    ->where('txn_type', StockTxnType::TransferOut)->first();
                if ($forward) {
                    $this->ledger->reverse($forward, $remarks, $user->id, 'transfer');
                }
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Cancelled,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Close a partly received transfer: what is still in transit is written back into the source
     * store (transfer_in at the dispatched cost, exactly the undelivered value).
     */
    public function closeShort(StockTransfer $transfer, User $user, string $reason): void
    {
        DB::transaction(function () use ($transfer, $user, $reason) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === StockTransferStatus::ClosedShort) {
                return;
            }
            if ($locked->status !== StockTransferStatus::PartiallyReceived) {
                throw ValidationException::withMessages(['transfer' => 'Only a partly received transfer can be closed short.']);
            }

            $from = Warehouse::query()->withTrashed()->findOrFail($locked->from_warehouse_id);
            foreach ($locked->items()->reorder('material_id')->lockForUpdate()->get() as $item) {
                $open = $item->inTransitQty();
                if (! $open->isPositive()) {
                    continue;
                }
                $value = Decimal::of($item->value)->minus($item->received_value);

                $this->ledger->post(new StockMovement(
                    source: $item,
                    type: StockTxnType::TransferIn,
                    warehouse: $from,
                    materialId: $item->material_id,
                    quantity: $open,
                    date: now()->toDateString(),
                    projectId: $locked->project_id,
                    unitCost: Decimal::of($item->unit_cost),
                    value: $value,
                    remarks: "Closed short {$locked->transfer_number}: {$reason}",
                    userId: $user->id,
                ));

                $item->forceFill(['short_closed_qty' => $open->toQuantity()])->save();
            }

            $locked->forceFill([
                'status' => StockTransferStatus::ClosedShort,
                'completed_at' => now(),
                'closed_by' => $user->id,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Value of a received quantity: the last receipt of a line takes the rest of its dispatched value.
     */
    private function valueShare(StockTransferItem $item, Decimal $quantity): Decimal
    {
        $rest = Decimal::of($item->value)->minus($item->received_value);
        if ($quantity->equals($item->inTransitQty())) {
            return $rest;
        }

        $value = $quantity->times($item->unit_cost)->round(Decimal::MONEY_SCALE);

        return $value->greaterThan($rest) ? $rest : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  material_id, quantity, remarks
     */
    private function replaceLines(StockTransfer $transfer, array $rows): void
    {
        StockTransferItem::query()->where('stock_transfer_id', $transfer->id)->get()->each->delete();

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

            (new StockTransferItem)->forceFill([
                'stock_transfer_id' => $transfer->id,
                'material_id' => $material->id,
                'unit_id' => $material->unit_id,
                'quantity' => $this->quantity($row['quantity'] ?? null, "items.{$index}.quantity")->toQuantity(),
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        $from = $this->scope->warehouse($project, $data['from_warehouse_id'] ?? null, 'from_warehouse_id');
        $to = $this->scope->warehouse($project, $data['to_warehouse_id'] ?? null, 'to_warehouse_id');
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'The destination must be a different store.']);
        }

        return [
            'transfer_date' => $data['transfer_date'],
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}

<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Material;
use App\Models\Masters\Warehouse;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of stock_transactions and (outside the reconcile repair) stock_balances.
 *
 * Every movement locks the warehouse + material balance row, checks idempotency, values the
 * movement at weighted average cost, inserts the ledger row and updates the cached balance in one
 * database transaction. Invariant kept for every warehouse + material:
 *
 *   quantity = Σ qty_in − Σ qty_out
 *   value    = Σ value of incoming rows − Σ value of outgoing rows
 *   avg_cost = round(value / quantity, 4), or 0 when quantity is 0
 *
 * Outgoing value is round(qty × avg_cost, 2), except that taking the whole balance takes the whole
 * value, so a warehouse emptied to zero quantity is always at zero value.
 */
class StockLedgerService
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function post(StockMovement $movement): StockTransaction
    {
        $quantity = $movement->quantity->round(Decimal::QTY_SCALE);
        if (! $quantity->isPositive()) {
            throw new LogicException('A stock movement needs a positive quantity.');
        }

        if ($movement->type === StockTxnType::Reversal) {
            throw new LogicException('Reversals are posted with reverse().');
        }

        $material = $this->assertTenant($movement->warehouse, $movement->materialId);

        return DB::transaction(function () use ($movement, $quantity, $material) {
            $balance = $this->lockBalance($movement->warehouse->id, $material->id);

            $existing = StockTransaction::query()
                ->where('source_type', $movement->source->getMorphClass())
                ->where('source_id', $movement->source->getKey())
                ->where('txn_type', $movement->type)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($movement->type->isIncoming()) {
                $unitCost = $movement->unitCost ?? throw new LogicException('An incoming movement needs a unit cost.');
                $unitCost = $unitCost->round(Decimal::RATE_SCALE);
                if ($unitCost->isNegative()) {
                    throw new LogicException('Unit cost cannot be negative.');
                }
                $value = ($movement->value ?? $quantity->times($unitCost))->round(Decimal::MONEY_SCALE);
                [$qtyIn, $qtyOut] = [$quantity, Decimal::zero()];
            } else {
                [$unitCost, $value] = $this->valueOutgoing($balance, $quantity, $movement, $material);
                [$qtyIn, $qtyOut] = [Decimal::zero(), $quantity];
            }

            $transaction = $this->insert([
                'project_id' => $movement->projectId,
                'warehouse_id' => $movement->warehouse->id,
                'material_id' => $material->id,
                'txn_date' => $movement->date,
                'txn_type' => $movement->type,
                'qty_in' => $qtyIn->toQuantity(),
                'qty_out' => $qtyOut->toQuantity(),
                'unit_cost' => $unitCost->toRate(),
                'value' => $value->toMoney(),
                'source_type' => $movement->source->getMorphClass(),
                'source_id' => $movement->source->getKey(),
                'remarks' => $movement->remarks,
                'created_by' => $movement->userId ?? Auth::id(),
            ]);

            $this->apply($balance, $qtyIn->minus($qtyOut), $qtyIn->isPositive() ? $value : $value->negate());

            return $transaction;
        });
    }

    /**
     * Post the compensating row for $original (same quantity, unit cost and value, opposite
     * direction). Idempotent: a row already reversed returns its existing reversal.
     */
    public function reverse(StockTransaction $original, ?string $remarks = null, ?int $userId = null, string $errorKey = 'document'): StockTransaction
    {
        if ($original->txn_type === StockTxnType::Reversal) {
            throw new LogicException('A reversal cannot itself be reversed.');
        }

        $warehouse = Warehouse::query()->withTrashed()->findOrFail($original->warehouse_id);
        $material = $this->assertTenant($warehouse, $original->material_id);

        return DB::transaction(function () use ($original, $remarks, $userId, $errorKey, $warehouse, $material) {
            $balance = $this->lockBalance($warehouse->id, $material->id);

            if ($existing = StockTransaction::query()->where('reverses_id', $original->id)->first()) {
                return $existing;
            }

            $wasIncoming = $original->isIncoming();
            $quantity = Decimal::of($wasIncoming ? $original->qty_in : $original->qty_out);
            $value = Decimal::of($original->value);

            if ($wasIncoming) {
                $remainingQty = Decimal::of($balance->quantity)->minus($quantity);
                $remainingValue = Decimal::of($balance->value)->minus($value);

                if ($remainingQty->isNegative() || $remainingValue->isNegative() || ($remainingQty->isZero() && ! $remainingValue->isZero())) {
                    throw ValidationException::withMessages([$errorKey => "Cannot reverse the receipt of {$material->name} in {$warehouse->name}: "
                        .'the stock has since been issued or moved. Reverse or adjust those movements first.']);
                }
            }

            $transaction = $this->insert([
                'project_id' => $original->project_id,
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'txn_date' => now()->toDateString(),
                'txn_type' => StockTxnType::Reversal,
                'qty_in' => $wasIncoming ? '0' : $quantity->toQuantity(),
                'qty_out' => $wasIncoming ? $quantity->toQuantity() : '0',
                'unit_cost' => Decimal::of($original->unit_cost)->toRate(),
                'value' => $value->toMoney(),
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'reverses_id' => $original->id,
                'remarks' => $remarks,
                'created_by' => $userId ?? Auth::id(),
            ]);

            $this->apply($balance, $wasIncoming ? $quantity->negate() : $quantity, $wasIncoming ? $value->negate() : $value);

            return $transaction;
        });
    }

    /**
     * Unlocked read of the cached balance (UI, draft validation). Postings re-check under lock.
     *
     * @return array{quantity: Decimal, avg_cost: Decimal, value: Decimal}
     */
    public function balance(int $warehouseId, int $materialId): array
    {
        $balance = StockBalance::query()->where('warehouse_id', $warehouseId)->where('material_id', $materialId)->first();

        return [
            'quantity' => Decimal::of($balance?->quantity),
            'avg_cost' => Decimal::of($balance?->avg_cost),
            'value' => Decimal::of($balance?->value),
        ];
    }

    /**
     * Lock (creating if needed) the balance row of a warehouse + material for the current transaction.
     * Callers posting several lines should post them in a stable order (material id) to avoid deadlocks.
     */
    public function lockBalance(int $warehouseId, int $materialId): StockBalance
    {
        $find = fn () => StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->lockForUpdate()
            ->first();

        if ($balance = $find()) {
            return $balance;
        }

        DB::table('stock_balances')->insertOrIgnore([
            'company_id' => $this->tenancy->require()->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'quantity' => 0,
            'avg_cost' => 0,
            'value' => 0,
            'updated_at' => now(),
        ]);

        return $find() ?? throw new LogicException('Stock balance row could not be locked.');
    }

    /**
     * @return array{0: Decimal, 1: Decimal} unit cost and value of an outgoing movement
     */
    private function valueOutgoing(StockBalance $balance, Decimal $quantity, StockMovement $movement, Material $material): array
    {
        $available = Decimal::of($balance->quantity);

        if ($quantity->greaterThan($available)) {
            throw ValidationException::withMessages([$movement->errorKey => "Insufficient stock of {$material->name} in "
                ."{$movement->warehouse->name}: available {$available->toQuantity()}, required {$quantity->toQuantity()}."]);
        }

        $average = Decimal::of($balance->avg_cost);
        $bookValue = Decimal::of($balance->value);

        if ($quantity->equals($available)) {
            return [$average, $bookValue];
        }

        $value = $quantity->times($average)->round(Decimal::MONEY_SCALE);

        return [$average, $value->greaterThan($bookValue) ? $bookValue : $value];
    }

    private function apply(StockBalance $balance, Decimal $qtyDelta, Decimal $valueDelta): void
    {
        $quantity = Decimal::of($balance->quantity)->plus($qtyDelta)->round(Decimal::QTY_SCALE);
        $value = Decimal::of($balance->value)->plus($valueDelta)->round(Decimal::MONEY_SCALE);

        if ($quantity->isNegative() || $value->isNegative()) {
            throw new LogicException('Stock balance would become negative.');
        }

        $balance->forceFill([
            'quantity' => $quantity->toQuantity(),
            'value' => $value->toMoney(),
            'avg_cost' => $quantity->isZero() ? '0' : $value->dividedBy($quantity)->toRate(),
            'updated_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): StockTransaction
    {
        $transaction = new StockTransaction;
        $transaction->forceFill($attributes)->save();

        return $transaction;
    }

    /**
     * The warehouse and material must belong to the current company; the scoped lookup fails
     * closed outside a company context.
     */
    private function assertTenant(Warehouse $warehouse, int $materialId): Material
    {
        $companyId = $this->tenancy->require()->id;

        if ((int) $warehouse->company_id !== $companyId) {
            throw new LogicException('The warehouse belongs to a different company.');
        }

        return Material::query()->withTrashed()->findOrFail($materialId);
    }
}

<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Models\Masters\Warehouse;
use App\Support\Math\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A forward stock movement to post through StockLedgerService. Incoming movements carry their
 * unit cost (and optionally an exact value); outgoing movements are always valued by the ledger
 * at the warehouse's current weighted average.
 */
final class StockMovement
{
    public function __construct(
        public readonly Model $source,
        public readonly StockTxnType $type,
        public readonly Warehouse $warehouse,
        public readonly int $materialId,
        public readonly Decimal $quantity,
        public readonly CarbonInterface|string $date,
        public readonly ?int $projectId = null,
        public readonly ?Decimal $unitCost = null,
        public readonly ?Decimal $value = null,
        public readonly ?string $remarks = null,
        public readonly ?int $userId = null,
        public readonly string $errorKey = 'items',
    ) {}
}

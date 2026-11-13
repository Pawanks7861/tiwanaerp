<?php

namespace App\Services\Scrap;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A forward scrap movement to post through ScrapLedgerService.
 */
final class ScrapMovement
{
    public function __construct(
        public readonly Model $reference,
        public readonly ScrapLedgerTxnType $type,
        public readonly Project $project,
        public readonly int $scrapTypeId,
        public readonly int $unitId,
        public readonly Decimal $quantity,
        public readonly CarbonInterface|string $date,
        public readonly ?string $referenceNumber = null,
        public readonly ?string $remarks = null,
        public readonly ?int $userId = null,
        public readonly string $errorKey = 'scrap',
    ) {}
}

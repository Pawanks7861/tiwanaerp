<?php

namespace App\Services\Scrap;

use App\Models\Scrap\ScrapBalance;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

class ScrapBalanceService
{
    public function availableQty(int $projectId, int $scrapTypeId, int $unitId): Decimal
    {
        $balance = ScrapBalance::query()
            ->where('project_id', $projectId)
            ->where('scrap_type_id', $scrapTypeId)
            ->where('unit_id', $unitId)
            ->first();

        return Decimal::of($balance?->quantity);
    }

    /**
     * @return Collection<int, array{scrap_type_id: int, unit_id: int, quantity: string, qty_generated: string, qty_dispatched: string, qty_adjusted_net: string}>
     */
    public function summary(int $projectId): Collection
    {
        return ScrapBalance::query()
            ->where('project_id', $projectId)
            ->where('quantity', '>', 0)
            ->with(['scrapType:id,name,code', 'unit:id,symbol'])
            ->orderBy('scrap_type_id')
            ->get()
            ->map(fn (ScrapBalance $row) => [
                'scrap_type_id' => $row->scrap_type_id,
                'scrap_type' => $row->scrapType?->only(['id', 'name', 'code']),
                'unit_id' => $row->unit_id,
                'unit' => $row->unit?->only(['id', 'symbol']),
                'quantity' => $row->quantity,
                'qty_generated' => $row->qty_generated,
                'qty_dispatched' => $row->qty_dispatched,
                'qty_adjusted_net' => $row->qty_adjusted_net,
            ]);
    }
}

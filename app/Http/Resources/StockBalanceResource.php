<?php

namespace App\Http\Resources;

use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\StockBalance;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $valuation = InventoryPresenter::seesValuation($request->user());

        return [
            'id' => $this->id,
            'item' => $this->whenLoaded('material', fn () => $this->material?->name),
            'item_code' => $this->whenLoaded('material', fn () => $this->material?->code),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'quantity' => $this->quantity,
            'unit' => $this->whenLoaded('material', fn () => $this->material?->relationLoaded('unit') ? $this->material->unit?->symbol : null),
            'reorder_level' => $this->whenLoaded('material', fn () => $this->material?->reorder_level),
            'low' => $this->whenLoaded('material', function () {
                $reorder = Decimal::of($this->material?->reorder_level);

                return $reorder->isPositive() && Decimal::of($this->quantity)->lessThanOrEqual($reorder);
            }),
            'avg_cost' => $this->when($valuation, $this->avg_cost),
            'value' => $this->when($valuation, $this->value),
        ];
    }
}

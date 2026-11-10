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
        $material = $this->relationLoaded('material') ? $this->material : null;

        return [
            'id' => $this->id,
            'material_id' => $this->material_id,
            'warehouse_id' => $this->warehouse_id,
            'item' => $material?->name,
            'item_code' => $material?->code,
            'material' => $material ? [
                'id' => $material->id,
                'code' => $material->code,
                'name' => $material->name,
                'item_type' => $material->item_type?->value ?? $material->item_type,
                'description' => $material->description,
            ] : null,
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->only(['id', 'code', 'name', 'type', 'project_id'])),
            'quantity' => $this->quantity,
            'unit' => $material?->relationLoaded('unit') ? $material->unit?->only(['id', 'symbol', 'name']) : null,
            'reorder_level' => $material?->reorder_level,
            'low' => $this->isLow($material),
            'avg_cost' => $this->when($valuation, $this->avg_cost),
            'value' => $this->when($valuation, $this->value),
        ];
    }

    private function isLow(mixed $material): bool
    {
        if ($material === null) {
            return false;
        }
        $reorder = Decimal::of($material->reorder_level);

        return $reorder->isPositive() && Decimal::of($this->quantity)->lessThanOrEqual($reorder);
    }
}

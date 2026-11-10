<?php

namespace App\Http\Resources;

use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Models\Inventory\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransaction
 */
class StockTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $valuation = InventoryPresenter::seesValuation($request->user());
        $own = (int) $this->project_id === (int) $request->route('project')?->id;

        $reference = $this->getAttribute('reference_meta');

        return [
            'id' => $this->id,
            'date' => $this->txn_date?->toDateString(),
            'movement_type' => $this->txn_type->value,
            'movement_label' => InventoryPresenter::txnLabel($this->txn_type),
            'item' => $this->whenLoaded('material', fn () => $this->material?->name),
            'material' => $this->whenLoaded('material', fn () => $this->material?->only(['id', 'code', 'name'])),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->only(['id', 'code', 'name'])),
            'in' => $this->qty_in,
            'out' => $this->qty_out,
            'avg_cost' => $this->when($valuation, $this->unit_cost),
            'value' => $this->when($valuation, $this->value),
            'source_type' => $own ? $this->source_type : null,
            'source_id' => $own ? $this->source_id : null,
            'reference' => $own ? ($reference['number'] ?? null) : null,
            'other_project' => ! $own,
            'remarks' => $own ? $this->remarks : null,
        ];
    }
}

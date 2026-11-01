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

        return [
            'id' => $this->id,
            'date' => $this->txn_date?->toDateString(),
            'movement_type' => $this->txn_type->value,
            'movement_label' => InventoryPresenter::txnLabel($this->txn_type),
            'item' => $this->whenLoaded('material', fn () => $this->material?->name),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'in' => $this->qty_in,
            'out' => $this->qty_out,
            'value' => $this->when($valuation, $this->value),
            'other_project' => ! $own,
            'remarks' => $own ? $this->remarks : null,
        ];
    }
}

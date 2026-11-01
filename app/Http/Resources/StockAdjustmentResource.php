<?php

namespace App\Http\Resources;

use App\Models\Inventory\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockAdjustment
 */
class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
        ];
    }
}

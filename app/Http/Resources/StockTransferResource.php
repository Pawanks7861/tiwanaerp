<?php

namespace App\Http\Resources;

use App\Models\Inventory\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'from_store' => $this->whenLoaded('fromWarehouse', fn () => $this->fromWarehouse?->name),
            'to_store' => $this->whenLoaded('toWarehouse', fn () => $this->toWarehouse?->name),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}

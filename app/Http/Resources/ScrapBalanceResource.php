<?php

namespace App\Http\Resources;

use App\Models\Scrap\ScrapBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScrapBalance */
class ScrapBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'scrap_type_id' => $this->scrap_type_id,
            'unit_id' => $this->unit_id,
            'quantity' => $this->quantity,
            'qty_generated' => $this->qty_generated,
            'qty_dispatched' => $this->qty_dispatched,
            'qty_adjusted_net' => $this->qty_adjusted_net,
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
            'scrap_type' => $this->whenLoaded('scrapType', fn () => ScrapTypeResource::make($this->scrapType)),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol'])),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Scrap\ScrapGenerationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScrapGenerationItem */
class ScrapGenerationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scrap_type_id' => $this->scrap_type_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_id' => $this->unit_id,
            'weight' => $this->weight,
            'condition' => $this->condition,
            'remarks' => $this->remarks,
            'scrap_type' => $this->whenLoaded('scrapType', fn () => ScrapTypeResource::make($this->scrapType)),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol'])),
        ];
    }
}

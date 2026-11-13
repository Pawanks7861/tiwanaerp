<?php

namespace App\Http\Resources;

use App\Models\Masters\ScrapType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScrapType */
class ScrapTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'scrap_category_id' => $this->scrap_category_id,
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol'])),
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'name', 'code'])),
            'is_active' => (bool) $this->is_active,
        ];
    }
}

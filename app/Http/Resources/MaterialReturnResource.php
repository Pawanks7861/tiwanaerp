<?php

namespace App\Http\Resources;

use App\Models\Inventory\MaterialReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialReturn
 */
class MaterialReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'return_date' => $this->return_date?->toDateString(),
            'type' => $this->return_type->value,
            'type_label' => $this->return_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
        ];
    }
}

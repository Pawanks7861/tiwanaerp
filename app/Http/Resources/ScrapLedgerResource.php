<?php

namespace App\Http\Resources;

use App\Models\Scrap\ScrapLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScrapLedgerEntry */
class ScrapLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'transaction_type' => $this->transaction_type->value,
            'transaction_type_label' => $this->transaction_type->label(),
            'scrap_type_id' => $this->scrap_type_id,
            'unit_id' => $this->unit_id,
            'quantity_in' => $this->quantity_in,
            'quantity_out' => $this->quantity_out,
            'reference_number' => $this->reference_number,
            'remarks' => $this->remarks,
            'scrap_type' => $this->whenLoaded('scrapType', fn () => $this->scrapType?->only(['id', 'name', 'code'])),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol'])),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Procurement\MaterialRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialRequest
 */
class MaterialRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'request_date' => $this->request_date?->toDateString(),
            'required_date' => $this->required_date?->toDateString(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remarks' => $this->remarks,
            'items_count' => $this->whenCounted('items'),
            'site' => $this->whenLoaded('site', fn () => $this->site?->name),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'ordered_qty' => $line->ordered_qty,
                'received_qty' => $line->received_qty,
                'remarks' => $line->remarks,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'item' => $this->itemMaster($line),
            ])->values()),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function itemMaster(mixed $line): ?array
    {
        $master = $line->relationLoaded('item') ? $line->item : ($line->relationLoaded('material') ? $line->material : null);

        return $master?->only(['id', 'code', 'name']);
    }
}

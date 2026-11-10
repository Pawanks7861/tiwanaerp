<?php

namespace App\Http\Resources;

use App\Models\Weighbridge\WeighbridgeReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WeighbridgeReceipt
 */
class WeighbridgeReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slip_no' => $this->slip_no,
            'vehicle_no' => $this->vehicle_no,
            'gross_weight' => $this->gross_weight,
            'tare_weight' => $this->tare_weight,
            'net_weight' => $this->net_weight,
            'weight_unit' => $this->weight_unit,
            'source' => $this->source?->value,
            'manual_reason' => $this->manual_reason,
            'operator_name' => $this->operator_name,
            'remarks' => $this->remarks,
            'weighed_at' => $this->weighed_at?->toIso8601String(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}

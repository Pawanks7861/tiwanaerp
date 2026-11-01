<?php

namespace App\Http\Resources;

use App\Models\Inventory\MaterialIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaterialIssue
 */
class MaterialIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_number' => $this->issue_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'issued_to' => $this->issuedToUser?->name ?? $this->subcontractor?->name ?? $this->issued_to_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'store' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
        ];
    }
}

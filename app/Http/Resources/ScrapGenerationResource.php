<?php

namespace App\Http\Resources;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Models\Scrap\ScrapGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScrapGeneration */
class ScrapGenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = $this->isEditable();
        $submitted = $this->status === ScrapGenerationStatus::Submitted;

        return [
            'id' => $this->id,
            'generation_number' => $this->generation_number,
            'generation_date' => $this->generation_date?->toDateString(),
            'location' => $this->location,
            'source_type' => $this->source_type->value,
            'source_type_label' => $this->source_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remarks' => $this->remarks,
            'items_count' => $this->whenCounted('items'),
            'items' => ScrapGenerationItemResource::collection($this->whenLoaded('items')),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'can' => $user ? [
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'approve' => $submitted && $user->can('approve', $this->resource),
                'cancel' => $user->can('cancel', $this->resource),
            ] : null,
        ];
    }
}

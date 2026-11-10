<?php

namespace App\Http\Resources;

use App\Models\Planning\ProjectMilestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMilestone
 */
class ProjectMilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'due_date' => $this->due_date?->toDateString(),
            'billing_percent' => $this->billing_percent,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'completed_at' => $this->completed_at?->toDateString(),
            'sort_order' => $this->sort_order,
            'tasks_count' => $this->whenCounted('tasks'),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'complete' => $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
            ] : null,
        ];
    }
}

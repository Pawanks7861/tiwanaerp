<?php

namespace App\Http\Resources;

use App\Models\Planning\ProjectTask;
use App\Support\Planning\TaskDelay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectTask
 */
class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $withCosts = $user?->can('boq.view_costs') ?? false;
        $delayDays = TaskDelay::days($this->resource);

        return [
            'id' => $this->id,
            'wbs_code' => $this->wbs_code,
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent?->only(['id', 'wbs_code', 'name'])),
            'milestone_id' => $this->milestone_id,
            'milestone' => $this->whenLoaded('milestone', fn () => $this->milestone?->only(['id', 'name', 'due_date'])),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),
            'priority' => $this->priority?->value,
            'priority_label' => $this->priority?->label(),
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_finish' => $this->planned_finish?->toDateString(),
            'actual_start' => $this->actual_start?->toDateString(),
            'actual_finish' => $this->actual_finish?->toDateString(),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only(['id', 'symbol', 'name'])),
            'planning_head' => $this->whenLoaded('parent', fn () => $this->parent?->only(['id', 'wbs_code', 'name'])),
            'is_head' => $this->resource->isPlanningHead(),
            'planned_qty' => $this->planned_qty,
            'total_qty' => $this->planned_qty,
            'executed_qty' => $this->completed_qty,
            'completed_qty' => $this->completed_qty,
            'hold_qty' => $this->hold_quantity,
            'hold_quantity' => $this->hold_quantity,
            'balance_qty' => $this->resource->balanceQuantity(),
            'completion_percentage' => $this->resource->completionPercent(),
            'progress_percent' => $this->progress_percent,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'delay_days' => $delayDays,
            'is_delayed' => TaskDelay::isOverdue($this->resource),
            // Optional historical BOQ reference — never required for Planning/DPR.
            'boq_item' => $this->whenLoaded('boqItem', fn () => $this->boqItem ? [
                'id' => $this->boqItem->id,
                'item_code' => $this->boqItem->item_code,
                'name' => $this->boqItem->name,
                'line_uid' => $this->boqItem->line_uid,
                'quantity' => $this->boqItem->quantity,
                'unit' => $this->boqItem->relationLoaded('unit') ? $this->boqItem->unit?->only(['id', 'symbol']) : null,
            ] : null),
            'dependencies' => $this->whenLoaded('dependencies', fn () => $this->dependencies->map(fn ($d) => [
                'id' => $d->id,
                'predecessor_id' => $d->predecessor_id,
                'type' => $d->type?->value,
                'lag_days' => $d->lag_days,
                'predecessor' => $d->relationLoaded('predecessor') ? $d->predecessor?->only(['id', 'wbs_code', 'name']) : null,
            ])->values()),
            'recent_progress' => $this->whenLoaded('progressEntries', fn () => $this->progressEntries->map(fn ($e) => [
                'id' => $e->id,
                'entry_date' => $e->entry_date?->toDateString(),
                'quantity' => $e->quantity,
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'posting_ref' => $e->posting_ref,
            ])->values()),
            'budget_amount' => $this->when($withCosts, $this->budget_amount),
            'actual_cost' => $this->when($withCosts, $this->actual_cost),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'update_status' => $user->can('updateProgress', $this->resource),
                'view_costs' => $withCosts,
            ] : null,
        ];
    }
}

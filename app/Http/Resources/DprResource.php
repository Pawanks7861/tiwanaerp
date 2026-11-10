<?php

namespace App\Http\Resources;

use App\Enums\SiteExecution\DprStatus;
use App\Models\SiteExecution\Dpr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dpr
 */
class DprResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = in_array($this->status, [DprStatus::Draft, DprStatus::Rejected], true);

        return [
            'id' => $this->id,
            'dpr_number' => $this->dpr_number,
            'dpr_date' => $this->dpr_date?->toDateString(),
            'revision' => $this->revision,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'weather' => $this->weather,
            'site_issues' => $this->site_issues,
            'remarks' => $this->remarks,
            'engineer' => $this->whenLoaded('engineer', fn () => $this->engineer?->only(['id', 'name'])),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'task_id' => $line->task_id,
                'task' => $line->relationLoaded('task') ? $line->task?->only(['id', 'wbs_code', 'name']) : null,
                'boq_item_id' => $line->boq_item_id,
                'boq_item' => $line->relationLoaded('boqItem') ? $line->boqItem?->only(['id', 'item_code', 'name', 'line_uid']) : null,
                'description' => $line->description,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'planned_qty' => $line->planned_qty,
                'executed_qty' => $line->executed_qty,
                'cumulative_qty' => $line->cumulative_qty,
                'balance_qty' => $line->balance_qty,
            ])->values()),
            'labours' => $this->whenLoaded('labours', fn () => $this->labours->map(fn ($l) => [
                'id' => $l->id,
                'labour_trade_id' => $l->labour_trade_id,
                'trade' => $l->relationLoaded('trade') ? $l->trade?->name : null,
                'subcontractor' => $l->relationLoaded('subcontractor') ? $l->subcontractor?->only(['id', 'name']) : null,
                'headcount' => $l->headcount,
                'hours' => $l->hours,
                'remarks' => $l->remarks,
            ])->values()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($e) => [
                'id' => $e->id,
                'equipment_type_id' => $e->equipment_type_id,
                'type' => $e->relationLoaded('equipmentType') ? $e->equipmentType?->name : null,
                'description' => $e->description,
                'working_hours' => $e->working_hours,
                'idle_hours' => $e->idle_hours,
            ])->values()),
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn ($m) => [
                'id' => $m->id,
                'material' => $m->relationLoaded('material') ? $m->material?->only(['id', 'code', 'name']) : null,
                'quantity' => $m->quantity,
                'unit' => $m->relationLoaded('unit') ? $m->unit?->symbol : null,
                'remarks' => $m->remarks,
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'refresh' => $editable && $user->can('update', $this->resource),
                'reopen' => $this->status === DprStatus::Approved && $user->can('reopen', $this->resource),
                'export' => $user->can('export', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
            ] : null,
        ];
    }
}

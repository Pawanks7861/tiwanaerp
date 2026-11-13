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
        $source = $this->source ?? 'direct';

        return [
            'id' => $this->id,
            'dpr_number' => $this->dpr_number,
            'dpr_date' => $this->dpr_date?->toDateString(),
            'revision' => $this->revision,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'source' => $source,
            'source_label' => $source === 'legacy_site_diary' ? 'Site diary (legacy)' : 'Direct entry',
            'weather' => $this->weather,
            'site_issues' => $this->site_issues,
            'remarks' => $this->remarks,
            'work_performed' => $this->work_performed,
            'constraints_summary' => $this->constraints_summary,
            'department' => $this->department,
            'agency_name' => $this->agency_name,
            'site_id' => $this->site_id,
            'engineer' => $this->whenLoaded('engineer', fn () => $this->engineer?->only(['id', 'name'])),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'task_id' => $line->task_id,
                'task' => $line->relationLoaded('task') ? $line->task?->only(['id', 'wbs_code', 'name']) : null,
                'boq_item_id' => $line->boq_item_id,
                'boq_item' => $line->relationLoaded('boqItem') ? $line->boqItem?->only(['id', 'item_code', 'name', 'line_uid']) : null,
                'description' => $line->description,
                'work_area' => $line->work_area,
                'activity_name_snapshot' => $line->activity_name_snapshot,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'unit_snapshot' => $line->unit_snapshot,
                'planned_start_date' => $line->planned_start_date?->toDateString(),
                'planned_end_date' => $line->planned_end_date?->toDateString(),
                'planned_qty' => $line->planned_qty,
                'previous_executed_qty' => $line->previous_executed_qty,
                'today_target_qty' => $line->today_target_qty,
                'executed_qty' => $line->executed_qty,
                'hold_quantity' => $line->hold_quantity,
                'hold_reason' => $line->hold_reason,
                'hold_since' => $line->hold_since?->toDateString(),
                'hold_responsible' => $line->hold_responsible,
                'hold_expected_resolution' => $line->hold_expected_resolution?->toDateString(),
                'hold_status' => $line->hold_status,
                'on_hold' => $line->on_hold,
                'cumulative_qty' => $line->cumulative_qty,
                'balance_qty' => $line->balance_qty,
                'target_variance' => $line->target_variance,
                'achievement_percent' => $line->achievement_percent,
                'completion_percent' => $line->completion_percent,
                'shortfall_reason' => $line->shortfall_reason,
                'remarks' => $line->remarks,
            ])->values()),
            'labours' => $this->whenLoaded('labours', fn () => $this->labours->map(fn ($l) => [
                'id' => $l->id,
                'labour_trade_id' => $l->labour_trade_id,
                'trade' => $l->relationLoaded('trade') ? $l->trade?->name : null,
                'subcontractor' => $l->relationLoaded('subcontractor') ? $l->subcontractor?->only(['id', 'name']) : null,
                'headcount' => $l->headcount,
                'skilled' => $l->skilled,
                'unskilled' => $l->unskilled,
                'operator_count' => $l->operator_count,
                'helper_count' => $l->helper_count,
                'hours' => $l->hours,
                'remarks' => $l->remarks,
                'is_informational' => $l->is_informational ?? true,
                'source' => $l->source ?? 'manual',
            ])->values()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($e) => [
                'id' => $e->id,
                'equipment_type_id' => $e->equipment_type_id,
                'type' => $e->relationLoaded('equipmentType') ? $e->equipmentType?->name : null,
                'description' => $e->description,
                'operator_name' => $e->operator_name,
                'start_time' => $e->start_time,
                'end_time' => $e->end_time,
                'duration_hours' => $e->duration_hours,
                'working_hours' => $e->working_hours,
                'idle_hours' => $e->idle_hours,
                'status' => $e->status,
                'remarks' => $e->remarks,
                'is_informational' => $e->is_informational ?? true,
            ])->values()),
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn ($m) => [
                'id' => $m->id,
                'material' => $m->relationLoaded('material') ? $m->material?->only(['id', 'code', 'name']) : null,
                'opening_qty' => $m->opening_qty,
                'received_qty' => $m->received_qty,
                'issued_qty' => $m->issued_qty,
                'closing_qty' => $m->closing_qty,
                'quantity' => $m->quantity,
                'unit' => $m->relationLoaded('unit') ? $m->unit?->symbol : null,
                'remarks' => $m->remarks,
                'is_informational' => $m->is_informational ?? false,
                'source' => $m->source ?? 'manual',
            ])->values()),
            'constraints' => $this->whenLoaded('constraints', fn () => $this->constraints->map(fn ($c) => [
                'id' => $c->id,
                'category' => $c->category,
                'description' => $c->description,
                'work_area' => $c->work_area,
                'task_id' => $c->task_id,
                'task' => $c->relationLoaded('task') ? $c->task?->only(['id', 'wbs_code', 'name']) : null,
                'responsible' => $c->responsible,
                'priority' => $c->priority,
                'raised_date' => $c->raised_date?->toDateString(),
                'expected_resolution' => $c->expected_resolution?->toDateString(),
                'status' => $c->status,
                'remarks' => $c->remarks,
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

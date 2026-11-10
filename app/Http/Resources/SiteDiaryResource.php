<?php

namespace App\Http\Resources;

use App\Models\SiteExecution\SiteDiary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SiteDiary
 */
class SiteDiaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = $this->isEditable();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'diary_date' => $this->diary_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'weather' => $this->weather,
            'temperature' => $this->temperature,
            'work_location' => $this->work_location,
            'work_performed' => $this->work_performed,
            'issues' => $this->issues,
            'safety_incidents' => $this->safety_incidents,
            'remarks' => $this->remarks,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'site' => $this->whenLoaded('site', fn () => $this->site ? [
                'id' => $this->site->id,
                'name' => $this->site->name,
                'address' => $this->site->getAttributes()['address'] ?? null,
                'is_active' => (bool) ($this->site->getAttributes()['is_active'] ?? true),
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'work_items_count' => $this->whenCounted('workItems'),
            'photos_count' => $this->whenCounted('photos'),
            'work_items' => $this->whenLoaded('workItems', fn () => $this->workItems->map(fn ($line) => [
                'id' => $line->id,
                'task_id' => $line->task_id,
                'task' => $line->relationLoaded('task') ? $line->task?->only(['id', 'wbs_code', 'name']) : null,
                'boq_item_id' => $line->boq_item_id,
                'boq_item' => $line->relationLoaded('boqItem') ? $line->boqItem?->only(['id', 'item_code', 'name', 'line_uid']) : null,
                'subcontractor_id' => $line->subcontractor_id,
                'subcontractor' => $line->relationLoaded('subcontractor') ? $line->subcontractor?->only(['id', 'code', 'name']) : null,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
            ])->values()),
            'labour' => $this->whenLoaded('labours', fn () => $this->labours->map(fn ($line) => [
                'id' => $line->id,
                'labour_trade_id' => $line->labour_trade_id,
                'trade' => $line->relationLoaded('trade') ? $line->trade?->name : null,
                'subcontractor_id' => $line->subcontractor_id,
                'subcontractor' => $line->relationLoaded('subcontractor') ? $line->subcontractor?->only(['id', 'code', 'name']) : null,
                'headcount' => $line->headcount,
                'hours' => $line->hours,
                'remarks' => $line->remarks,
            ])->values()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($line) => [
                'id' => $line->id,
                'equipment_type_id' => $line->equipment_type_id,
                'type' => $line->relationLoaded('equipmentType') ? $line->equipmentType?->name : null,
                'description' => $line->description,
                'working_hours' => $line->working_hours,
                'idle_hours' => $line->idle_hours,
            ])->values()),
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn ($line) => [
                'id' => $line->id,
                'material_id' => $line->material_id,
                'item' => $line->relationLoaded('material') ? $line->material?->only(['id', 'code', 'name']) : null,
                'quantity' => $line->quantity,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'remarks' => $line->remarks,
            ])->values()),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'uuid' => $photo->uuid,
                'caption' => $photo->caption,
                'mime' => $photo->mime,
                'size' => $photo->size_bytes,
                'latitude' => $photo->latitude,
                'longitude' => $photo->longitude,
                'taken_at' => $photo->taken_at?->toIso8601String(),
                'url' => route('api.v1.projects.site-diaries.photos.show', [
                    'project' => $this->project_id,
                    'siteDiary' => $this->id,
                    'photo' => $photo->id,
                ]),
            ])->values()),
            'rejection_reason' => $this->rejection_reason,
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'review' => $user->can('review', $this->resource),
                'approve' => $user->can('approve', $this->resource),
                'reject' => $user->can('reject', $this->resource),
                'attach_photo' => $editable && $user->can('update', $this->resource),
            ] : null,
        ];
    }
}

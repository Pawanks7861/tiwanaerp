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
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'diary_date' => $this->diary_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'work_location' => $this->work_location,
            'work_performed' => $this->work_performed,
            'issues' => $this->issues,
            'weather' => $this->weather,
            'remarks' => $this->remarks,
            'site' => $this->whenLoaded('site', fn () => $this->site?->name),
            'labour' => $this->whenLoaded('labours', fn () => $this->labours->map(fn ($line) => [
                'id' => $line->id,
                'labour_trade_id' => $line->labour_trade_id,
                'trade' => $line->relationLoaded('trade') ? $line->trade?->name : null,
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
                'url' => route('api.v1.projects.site-diaries.photos.show', [
                    'project' => $this->project_id,
                    'siteDiary' => $this->id,
                    'photo' => $photo->id,
                ]),
            ])->values()),
        ];
    }
}

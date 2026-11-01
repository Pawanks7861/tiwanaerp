<?php

namespace App\Http\Resources;

use App\Models\Labour\LabourAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabourAttendance
 */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'sync_uuid' => $this->sync_uuid,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'approval_status' => $this->approval_status->value,
            'approval_label' => $this->approval_status->label(),
            'punch_in' => $this->clock($this->punch_in),
            'punch_out' => $this->clock($this->punch_out),
            'working_hours' => $this->working_hours,
            'ot_hours' => $this->ot_hours,
            'remarks' => $this->remarks,
            'labour' => $this->whenLoaded('labour', fn () => [
                'id' => $this->labour->id,
                'code' => $this->labour->code,
                'name' => $this->labour->name,
                'trade' => $this->labour->relationLoaded('trade') ? $this->labour->trade?->name : null,
            ]),
            'site' => $this->whenLoaded('site', fn () => $this->site?->name),
        ];
    }

    private function clock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
    }
}

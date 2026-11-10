<?php

namespace App\Http\Resources;

use App\Http\Controllers\Labour\LabourPresenter;
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
        $user = $request->user();
        $amounts = $user && LabourPresenter::seesAttendanceAmounts($user);
        $wages = $user && LabourPresenter::seesWages($user);

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
            'wage_amount' => $this->when($amounts, $this->wage_amount),
            'ot_amount' => $this->when($amounts, $this->ot_amount),
            'remarks' => $this->remarks,
            'labour' => $this->whenLoaded('labour', fn () => [
                'id' => $this->labour->id,
                'code' => $this->labour->code,
                'name' => $this->labour->name,
                'trade' => $this->labour->relationLoaded('trade') ? $this->labour->trade?->name : null,
                'daily_wage' => $this->when($wages, $this->labour->daily_wage),
            ]),
            'site' => $this->whenLoaded('site', fn () => $this->site?->name),
            'can' => $user ? [
                'update' => $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                'unapprove' => $user->can('unapprove', $this->resource),
                'view_amounts' => $amounts,
            ] : null,
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

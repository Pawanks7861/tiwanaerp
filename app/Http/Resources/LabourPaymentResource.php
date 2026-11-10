<?php

namespace App\Http\Resources;

use App\Enums\Labour\LabourPaymentStatus;
use App\Http\Controllers\Labour\LabourPresenter;
use App\Models\Labour\LabourPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabourPayment
 */
class LabourPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $wages = $user && LabourPresenter::seesWages($user);
        $editable = $this->isEditable();
        $submitted = $this->status === LabourPaymentStatus::Submitted;

        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'remarks' => $this->remarks,
            'return_reason' => $this->return_reason,
            'total_gross' => $this->when($wages, $this->total_gross),
            'total_ot' => $this->when($wages, $this->total_ot),
            'total_deductions' => $this->when($wages, $this->total_deductions),
            'total_net' => $this->when($wages, $this->total_net),
            'paid_on' => $this->paid_on?->toDateString(),
            'payment_reference' => $this->payment_reference,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'labour_id' => $line->labour_id,
                'labour' => $line->relationLoaded('labour') && $line->labour ? [
                    'code' => $line->labour->code,
                    'name' => $line->labour->name,
                    'trade' => $line->labour->relationLoaded('trade') ? $line->labour->trade?->name : null,
                ] : null,
                'present_days' => $line->present_days,
                'half_days' => $line->half_days,
                'ot_hours' => $line->ot_hours,
                'gross_wage' => $this->when($wages, $line->gross_wage),
                'ot_amount' => $this->when($wages, $line->ot_amount),
                'advance_recovery' => $this->when($wages, $line->advance_recovery),
                'other_deductions' => $this->when($wages, $line->other_deductions),
                'net_amount' => $this->when($wages, $line->net_amount),
                'remarks' => $line->remarks,
            ])->values()),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'approve' => $submitted && (int) $this->submitted_by !== (int) $user->id && $user->can('approve', $this->resource),
                'send_back' => $submitted && $user->can('sendBack', $this->resource),
                'mark_paid' => $this->status === LabourPaymentStatus::Approved && $user->can('markPaid', $this->resource),
                'view_wages' => $wages,
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_gross' => $this->total_gross,
            'total_ot' => $this->total_ot,
            'total_deductions' => $this->total_deductions,
            'total_net' => $this->total_net,
            'paid_on' => $this->paid_on?->toDateString(),
            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}

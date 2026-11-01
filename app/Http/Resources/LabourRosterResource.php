<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourRosterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'code' => $this['code'],
            'name' => $this['name'],
            'mobile' => $this['mobile'],
            'trade' => $this['trade'],
            'daily_wage' => $this['daily_wage'],
            'ot_rate_per_hour' => $this['ot_rate_per_hour'],
            'advance_due' => $this['advance_due'],
        ];
    }
}

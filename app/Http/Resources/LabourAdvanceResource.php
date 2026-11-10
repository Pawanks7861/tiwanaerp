<?php

namespace App\Http\Resources;

use App\Models\Labour\LabourAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabourAdvance
 */
class LabourAdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'advance_date' => $this->advance_date?->toDateString(),
            'amount' => $this->amount,
            'recovered_amount' => $this->recovered_amount,
            'outstanding' => $this->outstanding()->toMoney(),
            'remarks' => $this->remarks,
            'labour' => $this->whenLoaded('labour', fn () => $this->labour ? [
                'id' => $this->labour->id,
                'code' => $this->labour->code,
                'name' => $this->labour->name,
            ] : null),
            'can' => $user ? [
                'delete' => $user->can('delete', $this->resource),
            ] : null,
        ];
    }
}

<?php

namespace App\Models\Utilities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'electricity_meter_reading_id', 'slot_code', 'slot_name', 'opening', 'closing', 'units',
])]
class ElectricityMeterTodReading extends Model
{
    protected function casts(): array
    {
        return [
            'opening' => 'decimal:4',
            'closing' => 'decimal:4',
            'units' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<ElectricityMeterReading, $this> */
    public function reading(): BelongsTo
    {
        return $this->belongsTo(ElectricityMeterReading::class, 'electricity_meter_reading_id');
    }
}

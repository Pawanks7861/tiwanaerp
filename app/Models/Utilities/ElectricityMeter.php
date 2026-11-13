<?php

namespace App\Models\Utilities;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'electricity_connection_id', 'meter_number', 'meter_type', 'make', 'model',
    'multiplication_factor', 'installed_at', 'removed_at', 'is_active', 'notes',
])]
class ElectricityMeter extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'multiplication_factor' => 'decimal:4',
            'installed_at' => 'date',
            'removed_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ElectricityConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ElectricityConnection::class, 'electricity_connection_id');
    }

    /** @return HasMany<ElectricityMeterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(ElectricityMeterReading::class);
    }
}

<?php

namespace App\Models\Utilities;

use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'utility_site_id', 'electricity_connection_id', 'electricity_meter_id', 'billing_month',
    'from_date', 'to_date', 'opening_kwh', 'closing_kwh', 'opening_kvah', 'closing_kvah',
    'mdi_reading', 'multiplication_factor', 'remarks',
])]
class ElectricityMeterReading extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ElectricityReadingStatus::class,
            'billing_month' => 'date',
            'from_date' => 'date',
            'to_date' => 'date',
            'opening_kwh' => 'decimal:4',
            'closing_kwh' => 'decimal:4',
            'opening_kvah' => 'decimal:4',
            'closing_kvah' => 'decimal:4',
            'mdi_reading' => 'decimal:4',
            'multiplication_factor' => 'decimal:4',
            'kwh_consumption' => 'decimal:4',
            'kvah_consumption' => 'decimal:4',
            'billing_demand' => 'decimal:4',
            'power_factor' => 'decimal:6',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === ElectricityReadingStatus::Draft;
    }

    /** @return BelongsTo<UtilitySite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(UtilitySite::class, 'utility_site_id');
    }

    /** @return BelongsTo<ElectricityConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ElectricityConnection::class, 'electricity_connection_id');
    }

    /** @return BelongsTo<ElectricityMeter, $this> */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(ElectricityMeter::class, 'electricity_meter_id');
    }

    /** @return HasMany<ElectricityMeterTodReading, $this> */
    public function todReadings(): HasMany
    {
        return $this->hasMany(ElectricityMeterTodReading::class);
    }

    /** @return HasOne<ElectricityBill, $this> */
    public function bill(): HasOne
    {
        return $this->hasOne(ElectricityBill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}

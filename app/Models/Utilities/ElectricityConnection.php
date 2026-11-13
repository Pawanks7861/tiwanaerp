<?php

namespace App\Models\Utilities;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'utility_site_id', 'vendor_id', 'connection_number', 'consumer_number', 'discom_name', 'state_code',
    'tariff_category', 'voltage_level', 'sanctioned_demand', 'contract_demand', 'demand_unit',
    'metering_type', 'tod_enabled', 'kwh_enabled', 'kvah_enabled', 'default_multiplication_factor',
    'billing_cycle', 'low_pf_threshold', 'is_active', 'notes',
])]
class ElectricityConnection extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'sanctioned_demand' => 'decimal:4',
            'contract_demand' => 'decimal:4',
            'default_multiplication_factor' => 'decimal:4',
            'low_pf_threshold' => 'decimal:4',
            'tod_enabled' => 'boolean',
            'kwh_enabled' => 'boolean',
            'kvah_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<UtilitySite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(UtilitySite::class, 'utility_site_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return HasMany<ElectricityMeter, $this> */
    public function meters(): HasMany
    {
        return $this->hasMany(ElectricityMeter::class);
    }

    /** @return HasMany<ElectricityTariff, $this> */
    public function tariffs(): HasMany
    {
        return $this->hasMany(ElectricityTariff::class);
    }
}

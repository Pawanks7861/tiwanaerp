<?php

namespace App\Models\Utilities;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'utility_site_id', 'electricity_connection_id', 'electricity_meter_reading_id', 'electricity_tariff_id',
    'bill_date', 'due_date', 'remarks',
])]
class ElectricityBill extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ElectricityBillStatus::class,
            'billing_month' => 'date',
            'bill_date' => 'date',
            'due_date' => 'date',
            'calculated_total' => 'decimal:2',
            'actual_bill_total' => 'decimal:2',
            'variance_total' => 'decimal:2',
            'cost_per_kwh' => 'decimal:4',
            'cost_per_kvah' => 'decimal:4',
            'demand_utilization_percent' => 'decimal:4',
            'excess_demand' => 'decimal:4',
            'tariff_snapshot' => 'array',
            'reading_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status?->isEditable() ?? false;
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

    /** @return BelongsTo<ElectricityMeterReading, $this> */
    public function reading(): BelongsTo
    {
        return $this->belongsTo(ElectricityMeterReading::class, 'electricity_meter_reading_id');
    }

    /** @return BelongsTo<ElectricityTariff, $this> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(ElectricityTariff::class, 'electricity_tariff_id');
    }

    /** @return HasMany<ElectricityBillComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ElectricityBillComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

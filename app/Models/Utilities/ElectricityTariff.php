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
    'electricity_connection_id', 'name', 'effective_from', 'effective_to',
    'tariff_category', 'state_code', 'description', 'is_active',
])]
class ElectricityTariff extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ElectricityConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ElectricityConnection::class, 'electricity_connection_id');
    }

    /** @return HasMany<ElectricityTariffComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ElectricityTariffComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}

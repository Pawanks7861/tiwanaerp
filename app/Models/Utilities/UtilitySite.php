<?php

namespace App\Models\Utilities;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'state_code', 'city', 'address', 'is_active'])]
class UtilitySite extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ElectricityConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(ElectricityConnection::class);
    }
}

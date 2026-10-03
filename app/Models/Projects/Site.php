<?php

namespace App\Models\Projects;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\MaterialRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'address', 'latitude', 'longitude', 'geofence_radius_m', 'is_active'])]
class Site extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius_m' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isInUse(): bool
    {
        return Warehouse::query()->where('site_id', $this->id)->exists()
            || MaterialRequest::query()->withTrashed()->where('site_id', $this->id)->exists();
    }
}

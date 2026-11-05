<?php

namespace App\Models\Boq;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqMaterialNorm extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $norm) {
            $boq = $norm->relationLoaded('boq') && $norm->boq?->id === $norm->boq_id
                ? $norm->boq
                : Boq::query()->withoutGlobalScopes()->find($norm->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'consumption_qty' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}

<?php

namespace App\Models\Boq;

use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\CostRateSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqCostComponent extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $component) {
            $boq = $component->relationLoaded('boq') && $component->boq?->id === $component->boq_id
                ? $component->boq
                : Boq::query()->withoutGlobalScopes()->find($component->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'component_type' => CostComponentType::class,
            'rate_source' => CostRateSource::class,
            'consumption_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'amount_per_boq_unit' => 'decimal:4',
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
}

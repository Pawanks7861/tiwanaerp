<?php

namespace App\Models\Boq;

use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Enums\Boq\PlanningTrackingBasis;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqPlanningMap extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $map) {
            $boq = $map->relationLoaded('boq') && $map->boq?->id === $map->boq_id
                ? $map->boq
                : Boq::query()->withoutGlobalScopes()->find($map->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'mapping_method' => PlanningBoqMappingMethod::class,
            'tracking_basis' => PlanningTrackingBasis::class,
            'allocated_boq_qty' => 'decimal:4',
            'planning_qty' => 'decimal:4',
            'conversion_factor' => 'decimal:4',
            'snapshot_boq_nos' => 'decimal:4',
            'snapshot_boq_qty' => 'decimal:4',
            'sequence' => 'integer',
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}

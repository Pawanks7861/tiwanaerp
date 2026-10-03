<?php

namespace App\Models\Planning;

use App\Enums\Planning\TaskPriority;
use App\Enums\Planning\TaskStatus;
use App\Models\Boq\BoqItem;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WBS node / schedule activity (the architecture uses project_tasks as the single WBS).
 * completed_qty, progress_percent and actual_cost are filled by DPR and costing in later phases.
 */
#[Fillable([
    'parent_id', 'milestone_id', 'boq_item_id', 'wbs_code', 'name', 'description', 'assigned_to', 'priority',
    'planned_start', 'planned_finish', 'duration_days', 'unit_id', 'planned_qty', 'budget_amount', 'sort_order',
])]
class ProjectTask extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'planned_start' => 'date',
            'planned_finish' => 'date',
            'actual_start' => 'date',
            'actual_finish' => 'date',
            'duration_days' => 'integer',
            'planned_qty' => 'decimal:4',
            'completed_qty' => 'decimal:4',
            'progress_percent' => 'decimal:4',
            'budget_amount' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<ProjectMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Links where this task is the successor (i.e. its predecessors).
     *
     * @return HasMany<TaskDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'successor_id');
    }

    /**
     * Links where this task is the predecessor.
     *
     * @return HasMany<TaskDependency, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'predecessor_id');
    }
}

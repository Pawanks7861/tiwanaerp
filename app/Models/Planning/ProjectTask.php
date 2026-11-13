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
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WBS node / Planning Head (parent) or Planning Activity (leaf).
 * BOQ link is optional/historical. completed_qty comes from approved DPR ledger;
 * hold_quantity is planning-authoritative; balance/completion are derived.
 */
#[Fillable([
    'parent_id', 'milestone_id', 'boq_item_id', 'wbs_code', 'name', 'description', 'assigned_to', 'priority',
    'planned_start', 'planned_finish', 'duration_days', 'unit_id', 'planned_qty', 'hold_quantity', 'budget_amount', 'sort_order',
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
            'hold_quantity' => 'decimal:4',
            'progress_percent' => 'decimal:4',
            'budget_amount' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** Planning Head = top-level WBS node (Work Area / section). */
    public function isPlanningHead(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Balance = Total − Executed − Hold (never negative without an override path).
     */
    public function balanceQuantity(): string
    {
        $balance = Decimal::of($this->planned_qty ?? '0')
            ->minus($this->completed_qty ?? '0')
            ->minus($this->hold_quantity ?? '0');

        return ($balance->isNegative() ? Decimal::zero() : $balance)->toQuantity();
    }

    /**
     * Completion % = Executed / Total × 100 (hold is not completed work).
     */
    public function completionPercent(): string
    {
        $planned = Decimal::of($this->planned_qty ?? '0');
        if (! $planned->isPositive()) {
            return $this->status === TaskStatus::Completed ? '100.0000' : '0.0000';
        }

        $pct = Decimal::of($this->completed_qty ?? '0')->times(100)->dividedBy($planned, 4);
        if ($pct->greaterThan(100)) {
            $pct = Decimal::of('100');
        }

        return $pct->toRate();
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
     * @return HasMany<ProgressEntry, $this>
     */
    public function progressEntries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class, 'task_id')->orderByDesc('entry_date')->orderByDesc('id');
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

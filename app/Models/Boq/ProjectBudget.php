<?php

namespace App\Models\Boq;

use App\Enums\Boq\BudgetSource;
use App\Enums\Boq\BudgetStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Versioned project cost budget. Approved budgets are never overwritten: a new version is created.
 * All columns are written by ProjectBudgetService.
 */
class ProjectBudget extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $budget) {
            if ($budget->getRawOriginal('status') === BudgetStatus::Draft->value) {
                return;
            }
            $allowed = ['status', 'updated_by', 'updated_at'];
            if (array_diff(array_keys($budget->getDirty()), $allowed) !== []) {
                throw self::lockedException();
            }
        });

        static::deleting(function (self $budget) {
            if ($budget->getRawOriginal('status') !== BudgetStatus::Draft->value) {
                throw self::lockedException();
            }
        });
    }

    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'budget' => 'This budget is approved and can no longer be changed. Generate a new version instead.',
        ]);
    }

    public function assertEditable(): void
    {
        if ($this->status !== BudgetStatus::Draft) {
            throw self::lockedException();
        }
    }

    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'source' => BudgetSource::class,
            'version' => 'integer',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    /**
     * @return HasMany<ProjectBudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProjectBudgetLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

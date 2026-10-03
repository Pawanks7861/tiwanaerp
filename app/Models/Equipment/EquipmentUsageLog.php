<?php

namespace App\Models\Equipment;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Daily use of an assigned machine. Posting (EquipmentUsageService::post) computes cost_amount and
 * writes the 'equipment' project cost; a posted log is frozen until the posting is reversed.
 */
class EquipmentUsageLog extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    public const LIFECYCLE_COLUMNS = ['cost_amount', 'posted_by', 'posted_at', 'updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $log) {
            if ($log->getOriginal('posted_at') === null) {
                return;
            }
            if (array_diff(array_keys($log->getDirty()), self::LIFECYCLE_COLUMNS) !== []) {
                throw ValidationException::withMessages(['usage' => 'A posted usage log cannot be changed. Reverse the posting first.']);
            }
        });

        static::deleting(function (self $log) {
            if ($log->posted_at !== null) {
                throw ValidationException::withMessages(['usage' => 'A posted usage log cannot be deleted. Reverse the posting first.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'opening_meter' => 'decimal:2',
            'closing_meter' => 'decimal:2',
            'working_hours' => 'decimal:2',
            'idle_hours' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    /**
     * @return BelongsTo<EquipmentAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EquipmentAssignment::class, 'equipment_assignment_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}

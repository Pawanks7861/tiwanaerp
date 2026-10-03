<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\RateBasis;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Labour\Labour;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipment deployed to a project from issue_date until return_date. rate_basis / rate are the
 * charge-out terms snapshotted at issue; usage logs are costed with them.
 */
class EquipmentAssignment extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'rate_basis' => RateBasis::class,
            'rate' => 'decimal:4',
            'issue_date' => 'date',
            'return_date' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<Labour, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Labour::class, 'operator_labour_id')->withTrashed();
    }

    /**
     * @return HasMany<EquipmentUsageLog, $this>
     */
    public function usageLogs(): HasMany
    {
        return $this->hasMany(EquipmentUsageLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}

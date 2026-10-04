<?php

namespace App\Models\Quality;

use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Models\Boq\BoqItem;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quality inspection against a checklist. Checkpoints are copied into quality_inspection_items at
 * creation. Completing it fixes the overall result (QualityInspectionService) and locks it.
 */
class QualityInspection extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'result' => InspectionResult::class,
            'inspection_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'A completed inspection cannot be changed.';
    }

    public function lockKey(): string
    {
        return 'inspection';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<QualityChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(QualityChecklist::class, 'quality_checklist_id');
    }

    /**
     * @return HasMany<QualityInspectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QualityInspectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Ncr, $this>
     */
    public function ncrs(): HasMany
    {
        return $this->hasMany(Ncr::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function engineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'engineer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}

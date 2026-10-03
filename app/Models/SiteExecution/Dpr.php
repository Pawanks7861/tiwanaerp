<?php

namespace App\Models\SiteExecution;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\SiteExecution\DprStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\SiteExecution\DprService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Daily progress report: one per project per day, built from the day's approved site diaries.
 * Final approval posts progress_entries (DprService::post); the DPR is then locked.
 */
#[Fillable(['weather', 'site_issues', 'remarks', 'engineer_id'])]
class Dpr extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'revision', 'approved_by', 'approved_at', 'reopened_by', 'reopened_at', 'reopen_reason',
        'pdf_path', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DprStatus::class,
            'dpr_date' => 'date',
            'revision' => 'integer',
            'approved_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This DPR is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'dpr';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * @return HasMany<DprItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DprItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<DprLabour, $this>
     */
    public function labours(): HasMany
    {
        return $this->hasMany(DprLabour::class)->orderBy('id');
    }

    /**
     * @return HasMany<DprEquipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(DprEquipment::class)->orderBy('id');
    }

    /**
     * @return HasMany<DprMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(DprMaterial::class)->orderBy('id');
    }

    public function approvalDocumentType(): string
    {
        return 'dpr';
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->dpr_number} - daily progress report";
    }

    public function approvalUrl(): string
    {
        return route('projects.dprs.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => DprStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(DprService::class)->post($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => DprStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => DprStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => DprStatus::Draft])->save();
    }
}

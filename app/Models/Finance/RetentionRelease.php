<?php

namespace App\Models\Finance;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Finance\RetentionReleaseStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\RetentionReleaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Release of retention held on one client RA bill or one subcontractor bill. Approval through the
 * engine (PM → Director) makes the amount due again on that bill; no cash moves here.
 */
class RetentionRelease extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'approved_by', 'approved_at', 'updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => RetentionReleaseStatus::class,
            'release_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This retention release is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'release';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function releasable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalDocumentType(): string
    {
        return 'retention_release';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->amount;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->release_number} - retention release";
    }

    public function approvalUrl(): string
    {
        return route('projects.retention.index', $this->project_id);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => RetentionReleaseStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(RetentionReleaseService::class)->approve($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => RetentionReleaseStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => RetentionReleaseStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => RetentionReleaseStatus::Draft])->save();
    }
}

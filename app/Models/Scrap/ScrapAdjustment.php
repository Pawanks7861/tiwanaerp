<?php

namespace App\Models\Scrap;

use App\Contracts\Approvable;
use App\Models\Approval\ApprovalRequest;
use App\Enums\Scrap\ScrapAdjustmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Scrap\ScrapAdjustmentService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['adjustment_date', 'direction', 'reason', 'remarks'])]
class ScrapAdjustment extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScrapAdjustmentStatus::class,
            'adjustment_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This scrap adjustment is submitted or posted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'scrap_adjustment';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ScrapAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ScrapAdjustmentItem::class)->orderBy('id');
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
        return 'scrap_adjustment';
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
        return "{$this->adjustment_number} — scrap adjustment";
    }

    public function approvalUrl(): string
    {
        return url("/projects/{$this->project_id}/scrap/adjustments/{$this->id}");
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => ScrapAdjustmentStatus::Submitted])->save();
    }

    public function onApprovalCompleted(?ApprovalRequest $request = null): void
    {
        app(ScrapAdjustmentService::class)->post($this, $this->completingApproverId($request));
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => ScrapAdjustmentStatus::Draft])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => ScrapAdjustmentStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => ScrapAdjustmentStatus::Draft])->save();
    }
}

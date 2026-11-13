<?php

namespace App\Models\Scrap;

use App\Contracts\Approvable;
use App\Enums\Scrap\ScrapGenerationStatus;
use App\Models\Approval\ApprovalRequest;
use App\Enums\Scrap\ScrapSourceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Department;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Scrap\ScrapGenerationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'generation_date', 'location', 'source_type', 'source_reference_type', 'source_reference_id',
    'department_id', 'remarks',
])]
class ScrapGeneration extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at',
        'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScrapGenerationStatus::class,
            'source_type' => ScrapSourceType::class,
            'generation_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This scrap generation is submitted or posted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'scrap_generation';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<ScrapGenerationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ScrapGenerationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
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
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvalDocumentType(): string
    {
        return 'scrap_generation';
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
        return "{$this->generation_number} — scrap generation";
    }

    public function approvalUrl(): string
    {
        return url("/projects/{$this->project_id}/scrap/generations/{$this->id}");
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => ScrapGenerationStatus::Submitted])->save();
    }

    public function onApprovalCompleted(?ApprovalRequest $request = null): void
    {
        app(ScrapGenerationService::class)->post($this, $this->completingApproverId($request));
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => ScrapGenerationStatus::Draft])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => ScrapGenerationStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => ScrapGenerationStatus::Draft])->save();
    }
}

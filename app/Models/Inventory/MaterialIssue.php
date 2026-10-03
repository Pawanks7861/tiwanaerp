<?php

namespace App\Models\Inventory;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\MaterialIssueService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue of material from a store to site work. Approval posts issue_out at the weighted average
 * cost and a 'material' row in the project cost ledger; the issue is then immutable.
 */
#[Fillable([
    'warehouse_id', 'issue_date', 'issued_to_user_id', 'subcontractor_id', 'issued_to_name', 'purpose', 'remarks',
])]
class MaterialIssue extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventoryDocumentStatus::class,
            'issue_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This material issue is submitted or posted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'issue';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_user_id');
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    /**
     * @return HasMany<MaterialIssueItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialIssueItem::class)->orderBy('id');
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
        return 'material_issue';
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
        return "{$this->issue_number} - material issue";
    }

    public function approvalUrl(): string
    {
        return route('projects.material-issues.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => InventoryDocumentStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(MaterialIssueService::class)->post($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => InventoryDocumentStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => InventoryDocumentStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => InventoryDocumentStatus::Draft])->save();
    }
}

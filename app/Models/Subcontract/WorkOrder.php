<?php

namespace App\Models\Subcontract;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Subcontract\WorkOrderService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Subcontract work order (architecture H.12). Approved through the engine (PM → Director); the
 * approved order is locked. Amounts are server-calculated by WorkOrderService.
 */
#[Fillable([
    'subcontractor_id', 'wo_date', 'scope', 'start_date', 'end_date', 'retention_percent', 'advance_amount',
    'tax_rate_id', 'tds_percent', 'terms',
])]
class WorkOrder extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'closed_by', 'closed_at', 'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'wo_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'retention_percent' => 'decimal:4',
            'advance_amount' => 'decimal:2',
            'tax_percent' => 'decimal:4',
            'tds_percent' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_value' => 'decimal:2',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This work order is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'work_order';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class)->withTrashed();
    }

    /**
     * @return HasMany<WorkOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(WorkOrderMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<SubcontractorBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SubcontractorBill::class);
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
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvalDocumentType(): string
    {
        return 'work_order';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->total_value;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->wo_number} - work order";
    }

    public function approvalUrl(): string
    {
        return route('projects.work-orders.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => WorkOrderStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(WorkOrderService::class)->approve($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => WorkOrderStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => WorkOrderStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => WorkOrderStatus::Draft])->save();
    }
}

<?php

namespace App\Models\Subcontract;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Subcontract\SubcontractorBillService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Measured running bill of a work order. While submitted, the certifier may adjust certified
 * quantities and deductions (CERTIFICATION_COLUMNS); final approval certifies the bill and posts
 * the 'subcontract' cost per item. After certification only the lifecycle may change.
 */
#[Fillable(['bill_date', 'subcontractor_invoice_no', 'period_from', 'period_to', 'remarks'])]
class SubcontractorBill extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'certified_by', 'certified_at', 'revision', 'reopened_by', 'reopened_at', 'reopen_reason',
        'gross_amount', 'tax_amount', 'retention_amount', 'advance_recovery', 'tds_amount', 'other_deductions', 'net_payable',
        'paid_amount', 'updated_by', 'updated_at',
    ];

    /** Amount columns the certifier may still change while the bill is submitted. */
    public const CERTIFICATION_COLUMNS = [
        'gross_amount', 'tax_amount', 'retention_amount', 'advance_recovery', 'tds_amount', 'other_deductions', 'net_payable',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $bill) {
            $original = $bill->getOriginal('status');
            if ($original === null || $original->isEditable() || $original === SubcontractorBillStatus::Submitted) {
                return;
            }
            if (array_intersect(array_keys($bill->getDirty()), self::CERTIFICATION_COLUMNS) !== []) {
                throw $bill->lockedException();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SubcontractorBillStatus::class,
            'bill_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'tax_percent' => 'decimal:4',
            'retention_percent' => 'decimal:4',
            'tds_percent' => 'decimal:4',
            'gross_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'retention_amount' => 'decimal:2',
            'advance_recovery' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'revision' => 'integer',
            'certified_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This subcontractor bill is submitted or certified and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'bill';
    }

    public function isCertified(): bool
    {
        return $this->status->isCertified();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }

    /**
     * @return HasMany<SubcontractorBillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubcontractorBillItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function approvalDocumentType(): string
    {
        return 'subcontractor_bill';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->net_payable;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->bill_number} - subcontractor bill";
    }

    public function approvalUrl(): string
    {
        return route('projects.subcontractor-bills.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => SubcontractorBillStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(SubcontractorBillService::class)->certify($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => SubcontractorBillStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => SubcontractorBillStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => SubcontractorBillStatus::Draft])->save();
    }
}

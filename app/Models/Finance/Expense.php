<?php

namespace App\Models\Finance;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\CostHead;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\Vendor;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Project expense. Approval (engine: PM → Accountant, final approver holds expenses.approve) posts
 * the amount excluding GST to the project cost under the category's cost head; a petty cash
 * expense is drained from its float at the same moment. Payment never posts cost.
 */
class Expense extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'revision', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'paid_on', 'payment_reference',
        'reversed_by', 'reversed_at', 'reversal_reason', 'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'payment_mode' => PaymentMode::class,
            'cost_head' => CostHead::class,
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'revision' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'paid_on' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This expense is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'expense';
    }

    public function isPettyCash(): bool
    {
        return $this->payment_mode === PaymentMode::PettyCash;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PettyCashAccount, $this>
     */
    public function pettyCashAccount(): BelongsTo
    {
        return $this->belongsTo(PettyCashAccount::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
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
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function approvalDocumentType(): string
    {
        return 'expense';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->total_amount;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->expense_number} - expense";
    }

    public function approvalUrl(): string
    {
        return route('projects.expenses.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => ExpenseStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(ExpenseService::class)->approve($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => ExpenseStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => ExpenseStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => ExpenseStatus::Draft])->save();
    }
}

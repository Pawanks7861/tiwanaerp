<?php

namespace App\Models\Inventory;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\MaterialReturnService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Site-to-store return (return_in at the original issue cost, credits the project cost ledger) or
 * return to vendor (return_to_vendor_out at the weighted average). No debit note in Phase 4.
 */
#[Fillable(['return_date', 'warehouse_id', 'vendor_id', 'grn_id', 'reason', 'remarks'])]
class MaterialReturn extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventoryDocumentStatus::class,
            'return_type' => MaterialReturnType::class,
            'return_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This material return is submitted or posted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'return';
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Grn, $this>
     */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    /**
     * @return HasMany<MaterialReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialReturnItem::class)->orderBy('id');
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
        return 'material_return';
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
        return "{$this->return_number} - {$this->return_type->label()}";
    }

    public function approvalUrl(): string
    {
        return route('projects.material-returns.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => InventoryDocumentStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(MaterialReturnService::class)->post($this, $approverId);
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

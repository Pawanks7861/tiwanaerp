<?php

namespace App\Models\Procurement;

use App\Contracts\Approvable;
use App\Enums\Procurement\MaterialRequestSourceType;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\ProcurementCategory;
use App\Enums\Procurement\RequestPriority;
use App\Models\Approval\ApprovalRequest;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Services\Procurement\MaterialRequestService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Site requirement for materials. Status changes only through MaterialRequestService, the approval
 * hooks and ProcurementQuantityService (ordered / received states).
 */
#[Fillable([
    'site_id', 'request_date', 'required_date', 'priority', 'procurement_category', 'equipment_meta', 'remarks',
    'source_type', 'boq_id', 'boq_item_id', 'drawing_revision_id', 'source_reason', 'source_label_snapshot',
])]
class MaterialRequest extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'approved_by', 'approved_at', 'cancelled_reason', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return [
            'status' => MaterialRequestStatus::class,
            'priority' => RequestPriority::class,
            'source_type' => MaterialRequestSourceType::class,
            'procurement_category' => ProcurementCategory::class,
            'equipment_meta' => 'array',
            'request_date' => 'date',
            'required_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This material request is submitted or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'material_request';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<MaterialRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sourceBoq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function sourceBoqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function sourceDrawingRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class, 'drawing_revision_id');
    }

    public function approvalDocumentType(): string
    {
        return 'material_request';
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
        return "{$this->request_number} - material request";
    }

    public function approvalUrl(): string
    {
        return route('projects.material-requests.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => MaterialRequestStatus::Submitted])->save();
        app(MaterialRequestService::class)->submitted($this);
    }

    public function onApprovalCompleted(?ApprovalRequest $request = null): void
    {
        app(MaterialRequestService::class)->markApproved($this, $this->completingApproverId($request));
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => MaterialRequestStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => MaterialRequestStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => MaterialRequestStatus::Draft])->save();
    }
}

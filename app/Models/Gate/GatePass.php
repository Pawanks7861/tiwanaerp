<?php

namespace App\Models\Gate;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Models\Weighbridge\WeighbridgeReceipt;
use App\Services\Gate\GatePassService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Validation\ValidationException;

class GatePass extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => GatePassDirection::class,
            'status' => GatePassStatus::class,
            'returnable' => 'boolean',
            'passed_at' => 'datetime',
            'expected_return_date' => 'date',
            'returned_at' => 'datetime',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw ValidationException::withMessages(['gate_pass' => 'This gate pass can no longer be edited.']);
        }
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<GatePassItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GatePassItem::class)->orderBy('sort_order');
    }

    /**
     * @return MorphOne<WeighbridgeReceipt, $this>
     */
    public function weighbridgeReceipt(): MorphOne
    {
        return $this->morphOne(WeighbridgeReceipt::class, 'weighable');
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approvalDocumentType(): string
    {
        return 'gate_pass';
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
        return "{$this->pass_number} - gate pass";
    }

    public function approvalUrl(): string
    {
        return route('projects.gate-passes.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => GatePassStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(GatePassService::class)->markApproved($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => GatePassStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => GatePassStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => GatePassStatus::Draft])->save();
    }
}

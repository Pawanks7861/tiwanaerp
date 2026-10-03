<?php

namespace App\Models\Boq;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Boq\BoqStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\BoqService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Bill of quantities. Revisions share boq_number and increase version; exactly one approved
 * revision per chain is current. Status changes only through BoqService / the approval hooks.
 */
#[Fillable(['title'])]
class Boq extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, SoftDeletes;

    /** Columns that may still change once the BOQ has left draft/rejected. */
    private const LIFECYCLE_COLUMNS = ['status', 'is_current', 'approved_by', 'approved_at', 'updated_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $boq) {
            $original = BoqStatus::from((string) $boq->getRawOriginal('status'));
            if ($original->isEditable()) {
                return;
            }

            $changed = array_diff(array_keys($boq->getDirty()), self::LIFECYCLE_COLUMNS);
            if ($changed !== []) {
                throw self::lockedException();
            }
        });

        static::deleting(function (self $boq) {
            if (! BoqStatus::from((string) $boq->getRawOriginal('status'))->isEditable()) {
                throw self::lockedException();
            }
        });
    }

    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'boq' => 'This BOQ is submitted or approved and can no longer be changed. Create a revision instead.',
        ]);
    }

    /**
     * Throws when lines or header may not be edited.
     */
    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw self::lockedException();
        }
    }

    protected function casts(): array
    {
        return [
            'status' => BoqStatus::class,
            'version' => 'integer',
            'is_current' => 'boolean',
            'total_cost_amount' => 'decimal:2',
            'total_client_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_boq_id');
    }

    /**
     * @return HasMany<BoqSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(BoqSection::class);
    }

    /**
     * @return HasMany<BoqItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function approvalDocumentType(): string
    {
        return 'boq';
    }

    public function approvalAmount(): ?string
    {
        return $this->total_client_amount;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "{$this->boq_number} v{$this->version} - {$this->title}";
    }

    public function approvalUrl(): string
    {
        return route('projects.boqs.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => BoqStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $request = $this->approvalRequests()->first();
        $approverId = $request?->actions()->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(BoqService::class)->markApproved($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => BoqStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => BoqStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => BoqStatus::Draft])->save();
    }
}

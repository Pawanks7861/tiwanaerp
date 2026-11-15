<?php

namespace App\Models\Boq;

use App\Contracts\Approvable;
use App\Enums\Boq\BoqStatus;
use App\Models\Approval\ApprovalRequest;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Documents\DrawingRevision;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\AdvancedBoqCommercialApprovalValidator;
use App\Services\Boq\AdvancedBoqTechnicalSnapshotService;
use App\Services\Boq\BoqService;
use App\Support\Boq\AdvancedBoqApprovalStage;
use App\Support\Boq\BoqCompanySettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    private const LIFECYCLE_COLUMNS = [
        'status', 'is_current', 'approved_by', 'approved_at', 'updated_by', 'updated_at',
        'technical_approved_at', 'technical_approved_by', 'technical_approval_snapshot', 'technical_snapshot_hash',
    ];

    /** Extra columns writable while Advanced commercial review window is open. */
    private const COMMERCIAL_COLUMNS = ['total_client_amount'];

    /**
     * Request-scoped Advanced revision clone counts. Never persisted.
     *
     * @var array<string, mixed>|null
     */
    public ?array $advancedCloneSummary = null;

    /** Request-scoped source BOQ id for the clone. Never persisted. */
    public ?int $sourceRevisionId = null;

    protected static function booted(): void
    {
        static::updating(function (self $boq) {
            $original = BoqStatus::from((string) $boq->getRawOriginal('status'));
            if ($original->isEditable()) {
                return;
            }

            $allowed = self::LIFECYCLE_COLUMNS;
            if ($boq->allowsCommercialRateEdits()) {
                $allowed = [...$allowed, ...self::COMMERCIAL_COLUMNS];
            }

            $changed = array_diff(array_keys($boq->getDirty()), $allowed);
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

    public static function technicalLockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'boq' => 'Technical costing is locked after technical approval. Return to technical review or create a revision to change engineering data.',
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

        if ($this->isTechnicallyApproved()) {
            throw self::technicalLockedException();
        }
    }

    /**
     * Throws when technical engineering/costing fields must not change.
     */
    public function assertTechnicalEditable(): void
    {
        if ($this->isTechnicallyApproved()) {
            throw self::technicalLockedException();
        }

        $this->assertEditable();
    }

    public function isTechnicallyApproved(): bool
    {
        return $this->technical_approved_at !== null;
    }

    /**
     * Commercial client rates may be edited after technical approval while still under review.
     */
    public function allowsCommercialRateEdits(): bool
    {
        if (! BoqCompanySettings::enabled()) {
            return false;
        }

        if ($this->status !== BoqStatus::Submitted || ! $this->isTechnicallyApproved()) {
            return false;
        }

        $pending = $this->pendingApprovalRequest();

        return $pending !== null && $pending->isPending() && $pending->isLastLevel();
    }

    public function approvalStage(): AdvancedBoqApprovalStage
    {
        return AdvancedBoqApprovalStage::for($this);
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
            'technical_approved_at' => 'datetime',
            'technical_approval_snapshot' => 'array',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function technicalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_approved_by');
    }

    /**
     * Approved (or historically linked) drawing revisions this BOQ was prepared from.
     *
     * @return BelongsToMany<DrawingRevision, $this>
     */
    public function drawingRevisions(): BelongsToMany
    {
        return $this->belongsToMany(DrawingRevision::class, 'boq_drawing_revisions')->withTimestamps();
    }

    /**
     * @return BelongsTo<DataImport, $this>
     */
    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class, 'source_import_id');
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
        $from = $this->status;
        $this->forceFill(['status' => BoqStatus::Submitted])->save();
        $this->recordApprovalAudit('submitted', $from, BoqStatus::Submitted, $this->pendingApprovalRequest());
    }

    /**
     * Intermediate level completed (Advanced: freeze technical snapshot before commercial).
     */
    public function onApprovalLevelCompleted(ApprovalRequest $request, int $completedLevel, User $actor): void
    {
        if (! BoqCompanySettings::enabled()) {
            return;
        }

        app(AdvancedBoqTechnicalSnapshotService::class)->freeze($this, $actor);
    }

    /**
     * Final level about to complete — commercial checks for Advanced BOQ.
     */
    public function beforeApprovalCompleted(ApprovalRequest $request, User $actor): void
    {
        if (! BoqCompanySettings::enabled()) {
            return;
        }

        // Single-step workflows still need a technical baseline before finalizing.
        if ($this->technical_approved_at === null) {
            app(AdvancedBoqTechnicalSnapshotService::class)->freeze($this, $actor);
        }

        app(AdvancedBoqCommercialApprovalValidator::class)->assertReady($this, $actor);
    }

    public function onApprovalCompleted(?ApprovalRequest $request = null): void
    {
        $from = $this->status;
        app(BoqService::class)->markApproved($this, $this->completingApproverId($request));
        $this->recordApprovalAudit('approved', $from, BoqStatus::Approved, $request);
    }

    public function onApprovalRejected(): void
    {
        $from = $this->status;
        app(AdvancedBoqTechnicalSnapshotService::class)->clear($this, 'rejected');
        $this->forceFill(['status' => BoqStatus::Rejected])->save();
        $this->recordApprovalAudit('rejected', $from, BoqStatus::Rejected, $this->latestApprovalRequest);
    }

    public function onApprovalSentBack(): void
    {
        $from = $this->status;
        app(AdvancedBoqTechnicalSnapshotService::class)->clear($this, 'sent_back_to_technical');
        $this->forceFill(['status' => BoqStatus::Draft])->save();
        $this->recordApprovalAudit('sent_back', $from, BoqStatus::Draft, $this->latestApprovalRequest);
    }

    public function onApprovalCancelled(): void
    {
        $from = $this->status;
        app(AdvancedBoqTechnicalSnapshotService::class)->clear($this, 'withdrawn');
        $this->forceFill(['status' => BoqStatus::Draft])->save();
        $this->recordApprovalAudit('withdrawn', $from, BoqStatus::Draft, $this->latestApprovalRequest);
    }
}

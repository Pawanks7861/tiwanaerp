<?php

namespace App\Models\Quality;

use App\Enums\Quality\NcrSeverity;
use App\Enums\Quality\NcrStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Non-conformance report. Details are editable while open / in progress; after resolution only the
 * lifecycle stamps move (NcrService), and a closed NCR never changes again.
 */
class Ncr extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'resolved_by', 'resolved_at', 'verified_by', 'verified_at', 'verification_remarks', 'closed_by', 'closed_at',
        'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $ncr) {
            if ($ncr->getOriginal('status') === NcrStatus::Closed) {
                throw $ncr->lockedException();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => NcrStatus::class,
            'severity' => NcrSeverity::class,
            'target_date' => 'date',
            'resolved_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return $this->getOriginal('status') === NcrStatus::Closed
            ? 'A closed NCR cannot be changed.'
            : 'NCR details can only be changed while it is open or in progress.';
    }

    public function lockKey(): string
    {
        return 'ncr';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<QualityInspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class, 'quality_inspection_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}

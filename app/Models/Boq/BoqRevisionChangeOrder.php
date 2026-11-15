<?php

namespace App\Models\Boq;

use App\Enums\Boq\RevisionChangeOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Revision-pair Change Order / Variation Summary (Advanced BOQ).
 * Snapshot of comparison between from_boq and to_boq; does not replace BOQ approval.
 */
class BoqRevisionChangeOrder extends Model
{
    use Auditable, BelongsToCompany, Blameable, LocksWhenNotEditable, SoftDeletes;

    protected $guarded = ['*'];

    /** @var list<string> */
    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'commercial_variation', 'internal_cost_variation', 'margin_variation',
        'comparison_snapshot', 'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RevisionChangeOrderStatus::class,
            'commercial_variation' => 'decimal:2',
            'internal_cost_variation' => 'decimal:2',
            'margin_variation' => 'decimal:2',
            'comparison_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This change order is under review or approved and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'change_order';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fromBoq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'from_boq_id');
    }

    public function toBoq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'to_boq_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}

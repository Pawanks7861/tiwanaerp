<?php

namespace App\Models\Boq;

use App\Enums\Boq\VariationChangeType;
use App\Enums\Boq\VariationSourceType;
use App\Enums\Boq\VariationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoqVariation extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    protected $guarded = ['*'];

    /** @var list<string> */
    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'revenue_impact', 'cost_impact', 'margin_impact',
        'updated_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VariationStatus::class,
            'change_order_type' => VariationChangeType::class,
            'source_type' => VariationSourceType::class,
            'client_instruction_date' => 'date',
            'revenue_impact' => 'decimal:2',
            'cost_impact' => 'decimal:2',
            'margin_impact' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This variation is submitted or approved and can no longer be changed. Create a correcting variation instead.';
    }

    public function lockKey(): string
    {
        return 'variation';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    /**
     * @return BelongsTo<DrawingRevision, $this>
     */
    public function drawingRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class);
    }

    /**
     * @return HasMany<BoqVariationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BoqVariationItem::class, 'variation_id')->orderBy('sequence')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}

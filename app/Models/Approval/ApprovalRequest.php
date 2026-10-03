<?php

namespace App\Models\Approval;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'current_level' => 'integer',
            'status' => ApprovalStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stepForLevel(int $level): ?array
    {
        return collect($this->steps)->firstWhere('level', $level);
    }

    public function currentStep(): ?array
    {
        return $this->stepForLevel($this->current_level);
    }

    public function isLastLevel(): bool
    {
        return $this->current_level >= (int) collect($this->steps)->max('level');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}

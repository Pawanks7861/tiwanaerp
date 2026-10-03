<?php

namespace App\Models\Labour;

use App\Enums\Labour\LabourPaymentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wage payment batch for a period, built from approved, unpaid attendance. Settles the wage
 * liability; the cost was posted at attendance approval, so this document never touches the
 * project cost ledger.
 */
class LabourPayment extends Model
{
    use Auditable, BelongsToCompany, Blameable, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'paid_by', 'paid_at',
        'paid_on', 'payment_reference', 'return_reason', 'paid_amount', 'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => LabourPaymentStatus::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'total_gross' => 'decimal:2',
            'total_ot' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This labour payment is submitted and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'payment';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<LabourPaymentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(LabourPaymentLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<LabourAttendance, $this>
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(LabourAttendance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
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
}

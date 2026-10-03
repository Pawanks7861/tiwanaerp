<?php

namespace App\Models\Finance;

use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentMode;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Crm\Client;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Receipt from a client or payment to a vendor, subcontractor or labour batch. Cash only: it never
 * writes the project cost ledger. Approval (payments.approve, maker ≠ checker) makes its
 * allocations count.
 */
class Payment extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'direction' => PaymentDirection::class,
            'party_type' => PaymentPartyType::class,
            'mode' => PaymentMode::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This payment is approved or cancelled and can no longer be changed.';
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
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
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

    /** Party name (client, vendor, subcontractor or labour batch number). */
    public function partyName(): ?string
    {
        return match ($this->party_type) {
            PaymentPartyType::Client => Client::query()->withTrashed()->whereKey($this->party_id)->value('company_name'),
            PaymentPartyType::Vendor => Vendor::query()->withTrashed()->whereKey($this->party_id)->value('name'),
            PaymentPartyType::Subcontractor => Subcontractor::query()->withTrashed()->whereKey($this->party_id)->value('name'),
            PaymentPartyType::LabourPayment => LabourPayment::query()->withTrashed()->whereKey($this->party_id)->value('payment_number'),
            default => null,
        };
    }
}

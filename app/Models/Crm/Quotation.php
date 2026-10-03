<?php

namespace App\Models\Crm;

use App\Enums\Crm\QuotationStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client quotation. Each revision is its own row sharing quotation_number (revision 0, 1, ...).
 * Only drafts are editable; accepted quotations never change except for the one-time project
 * conversion stamp.
 */
class Quotation extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = [
        'status', 'sent_at', 'decided_by', 'decided_at', 'rejection_reason', 'converted_project_id', 'converted_by', 'converted_at',
        'client_id', 'updated_by', 'updated_at',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'tax_type' => TaxType::class,
            'revision' => 'integer',
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'Only a draft quotation can be changed. Revise it to make changes.';
    }

    public function lockKey(): string
    {
        return 'quotation';
    }

    public function displayNumber(): string
    {
        return $this->revision > 0 ? "{$this->quotation_number} R{$this->revision}" : $this->quotation_number;
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quotation_id')->withTrashed();
    }

    /**
     * @return HasMany<self, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_quotation_id');
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function convertedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'converted_project_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

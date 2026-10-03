<?php

namespace App\Models\Finance;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Boq\Boq;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Crm\Client;
use App\Models\Masters\TaxRate;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ClientInvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client running-account (RA) bill measured against the current BOQ and the executed (DPR)
 * quantity. The tax invoice number is assigned at certification so it stays gapless; until then
 * the bill is known by its RA sequence. A certified bill is locked; receipts only move the
 * received_amount cache and the paid statuses.
 */
class ClientInvoice extends Model implements Approvable
{
    use Auditable, BelongsToCompany, Blameable, HasApprovals, HasAttachments, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'invoice_number', 'certified_by', 'certified_at', 'received_amount', 'updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ClientInvoiceStatus::class,
            'tax_type' => TaxType::class,
            'invoice_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'ra_sequence' => 'integer',
            'gross_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'invoice_total' => 'decimal:2',
            'retention_percent' => 'decimal:4',
            'retention_amount' => 'decimal:2',
            'advance_recovery' => 'decimal:2',
            'tds_percent' => 'decimal:4',
            'tds_amount' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'certified_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'This RA bill is submitted or certified and can no longer be changed.';
    }

    public function lockKey(): string
    {
        return 'invoice';
    }

    public function isCertified(): bool
    {
        return $this->status->isCertified();
    }

    /** "INV-PRJ001-0003" once certified, "RA-3 (draft)" before. */
    public function displayNumber(): string
    {
        return $this->invoice_number ?? "RA-{$this->ra_sequence} (draft)";
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class)->withTrashed();
    }

    /**
     * @return HasMany<ClientInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ClientInvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function approvalDocumentType(): string
    {
        return 'client_invoice';
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->invoice_total;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return "RA bill {$this->ra_sequence} - client bill";
    }

    public function approvalUrl(): string
    {
        return route('projects.ra-bills.show', [$this->project_id, $this->id]);
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => ClientInvoiceStatus::Submitted])->save();
    }

    public function onApprovalCompleted(): void
    {
        $approverId = $this->approvalRequests()->first()?->actions()
            ->where('action', ApprovalActionType::Approved)->reorder('id', 'desc')->value('user_id');

        app(ClientInvoiceService::class)->certify($this, $approverId);
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => ClientInvoiceStatus::Rejected])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => ClientInvoiceStatus::Draft])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => ClientInvoiceStatus::Draft])->save();
    }
}

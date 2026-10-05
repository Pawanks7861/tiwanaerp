<?php

namespace App\Integrations\Tally;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\PettyCashTxnType;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Integrations\Tally\Mappers\ClientInvoiceTallyMapper;
use App\Integrations\Tally\Mappers\ClientReceiptTallyMapper;
use App\Integrations\Tally\Mappers\ExpenseTallyMapper;
use App\Integrations\Tally\Mappers\LabourPaymentTallyMapper;
use App\Integrations\Tally\Mappers\LabourSettlementTallyMapper;
use App\Integrations\Tally\Mappers\PettyCashTallyMapper;
use App\Integrations\Tally\Mappers\SubcontractBillTallyMapper;
use App\Integrations\Tally\Mappers\SubcontractPaymentTallyMapper;
use App\Integrations\Tally\Mappers\VendorBillTallyMapper;
use App\Integrations\Tally\Mappers\VendorPaymentTallyMapper;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Finance\VendorBill;
use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallySyncRecord;
use App\Models\Labour\LabourPayment;
use App\Models\Subcontract\SubcontractorBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Which finalized ERP documents may become a Tally voucher, and which mapper owns the amounts.
 * Drafts, GRNs, stock rows and the project cost ledger are not in this list.
 */
class TallyDocumentRegistry
{
    public function __construct(
        private readonly TallyLedgerMapper $ledgers,
        private readonly ClientInvoiceTallyMapper $invoices,
        private readonly ClientReceiptTallyMapper $receipts,
        private readonly VendorBillTallyMapper $vendorBills,
        private readonly VendorPaymentTallyMapper $vendorPayments,
        private readonly ExpenseTallyMapper $expenses,
        private readonly SubcontractBillTallyMapper $subcontractBills,
        private readonly SubcontractPaymentTallyMapper $subcontractPayments,
        private readonly LabourPaymentTallyMapper $labourBatches,
        private readonly LabourSettlementTallyMapper $labourSettlements,
        private readonly PettyCashTallyMapper $pettyCash,
    ) {}

    public function canExport(Model $model): bool
    {
        return match ($model::class) {
            ClientInvoice::class => $model->status->isCertified() && filled($model->invoice_number),
            Payment::class => $model->status === PaymentStatus::Approved,
            VendorBill::class => $model->status->isApproved(),
            Expense::class => in_array($model->status, ExpenseStatus::approvedStates(), true) && $model->reversed_at === null,
            SubcontractorBill::class => $model->status->isCertified(),
            LabourPayment::class => in_array($model->status, LabourPaymentStatus::settled(), true),
            PettyCashTransaction::class => $model->type !== PettyCashTxnType::ExpenseOut,
            default => false,
        };
    }

    public function canCancel(Model $model): bool
    {
        $cancelled = ($model instanceof Payment && $model->status === PaymentStatus::Cancelled)
            || ($model instanceof Expense && $model->reversed_at !== null);
        if (! $cancelled) {
            return false;
        }

        return TallySyncRecord::query()
            ->where('source_type', $model->getMorphClass())
            ->where('source_id', $model->getKey())
            ->where('action', 'export')
            ->where('status', \App\Enums\Integrations\TallySyncStatus::Synced)
            ->exists();
    }

    public function voucher(Model $model, TallyConnection $connection): TallyVoucher
    {
        $this->load($model);
        $centre = $this->ledgers->costCentre($connection, $this->projectId($model));

        $voucher = match ($model::class) {
            ClientInvoice::class => $this->invoices->map($model, $this->ledgers, $centre),
            VendorBill::class => $this->vendorBills->map($model, $this->ledgers, $centre),
            Expense::class => $this->expenses->map($model, $this->ledgers, $centre),
            SubcontractorBill::class => $this->subcontractBills->map($model, $this->ledgers, $centre),
            LabourPayment::class => $this->labourBatches->map($model, $this->ledgers, $centre),
            PettyCashTransaction::class => $this->pettyCash->map($model, $this->ledgers, $centre),
            Payment::class => $this->paymentVoucher($model, $centre),
            default => throw new TallyException('This document is not part of the Tally accounting integration.'),
        };
        $voucher->assertBalanced();

        return $voucher;
    }

    /**
     * @return array{type: string, reference: string, project_id: ?int, date: ?string, amount: string}
     */
    public function meta(Model $model): array
    {
        $this->load($model);

        return [
            'type' => $model->getMorphClass(),
            'reference' => $this->reference($model),
            'project_id' => $this->projectId($model),
            'date' => $this->date($model),
            'amount' => $this->amount($model),
        ];
    }

    public function find(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type);
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * @param  array{project_id?: int|null, from?: string|null, to?: string|null, type?: string|null}  $filters
     * @return Collection<int, Model>
     */
    public function candidates(array $filters): Collection
    {
        $type = $filters['type'] ?? null;
        $models = collect();
        $sources = [
            'client_invoice' => fn () => ClientInvoice::query()->whereIn('status', array_map(fn ($s) => $s->value, ClientInvoiceStatus::certifiedStates())),
            'payment' => fn () => Payment::query()->where('status', PaymentStatus::Approved->value),
            'vendor_bill' => fn () => VendorBill::query()->whereIn('status', array_map(fn ($s) => $s->value, VendorBillStatus::approvedStates())),
            'expense' => fn () => Expense::query()->whereIn('status', array_map(fn ($s) => $s->value, ExpenseStatus::approvedStates()))->whereNull('reversed_at'),
            'subcontractor_bill' => fn () => SubcontractorBill::query()->whereIn('status', array_map(fn ($s) => $s->value, SubcontractorBillStatus::certifiedStates())),
            'labour_payment' => fn () => LabourPayment::query()->whereIn('status', array_map(fn ($s) => $s->value, LabourPaymentStatus::settled())),
            'petty_cash_transaction' => fn () => PettyCashTransaction::query()->whereIn('type', [PettyCashTxnType::FundIn->value, PettyCashTxnType::ReturnOut->value]),
        ];

        foreach ($sources as $name => $query) {
            if ($type && $type !== $name) {
                continue;
            }
            $builder = $query();
            if (! empty($filters['project_id']) && $name !== 'petty_cash_transaction') {
                $builder->where('project_id', $filters['project_id']);
            }
            $models = $models->concat($builder->orderByDesc('id')->limit(50)->get());
        }

        return $models->take(50)->values();
    }

    private function paymentVoucher(Payment $payment, ?string $costCentre): TallyVoucher
    {
        if ($payment->direction === PaymentDirection::Receipt) {
            return $this->receipts->map($payment, $this->ledgers, $costCentre);
        }

        return match ($payment->party_type) {
            PaymentPartyType::Vendor => $this->vendorPayments->map($payment, $this->ledgers, $costCentre),
            PaymentPartyType::Subcontractor => $this->subcontractPayments->map($payment, $this->ledgers, $costCentre),
            PaymentPartyType::LabourPayment => $this->labourSettlements->map($payment, $this->ledgers, $costCentre),
            default => throw new TallyException('This payment is not part of the Tally accounting integration.'),
        };
    }

    private function load(Model $model): void
    {
        if (method_exists($model, 'project')) {
            $model->loadMissing('project');
        }
        if ($model instanceof Payment) {
            $model->loadMissing('allocations');
        }
        if ($model instanceof PettyCashTransaction) {
            $model->loadMissing('account.project');
        }
    }

    private function projectId(Model $model): ?int
    {
        if ($model instanceof PettyCashTransaction) {
            return $model->account?->project_id;
        }

        return $model->project_id ?? null;
    }

    private function reference(Model $model): string
    {
        return match ($model::class) {
            ClientInvoice::class => (string) ($model->invoice_number ?: 'RA-'.$model->ra_sequence),
            Payment::class => $model->payment_number,
            VendorBill::class => $model->bill_number,
            Expense::class => $model->expense_number,
            SubcontractorBill::class => $model->bill_number,
            LabourPayment::class => $model->payment_number,
            PettyCashTransaction::class => 'PC-'.$model->id,
            default => (string) $model->getKey(),
        };
    }

    private function date(Model $model): ?string
    {
        $value = match ($model::class) {
            ClientInvoice::class => $model->invoice_date,
            Payment::class => $model->payment_date,
            VendorBill::class => $model->vendor_invoice_date,
            Expense::class => $model->expense_date,
            SubcontractorBill::class => $model->bill_date,
            LabourPayment::class => $model->period_to,
            PettyCashTransaction::class => $model->txn_date,
            default => null,
        };

        return $value?->toDateString();
    }

    private function amount(Model $model): string
    {
        $value = match ($model::class) {
            ClientInvoice::class, SubcontractorBill::class => $model->net_payable,
            VendorBill::class => $model->net_payable,
            Payment::class, PettyCashTransaction::class => $model->amount,
            Expense::class => $model->total_amount,
            LabourPayment::class => $model->total_net,
            default => '0',
        };

        return (string) $value;
    }
}

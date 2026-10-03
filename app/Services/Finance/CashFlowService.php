<?php

namespace App\Services\Finance;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\PettyCashTxnType;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Finance\VendorBill;
use App\Models\Labour\LabourPayment;
use App\Models\Subcontract\SubcontractorBill;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Company cash movements, built only from cash documents (never the cost ledger):
 * approved receipts (in) and payments (out), expenses paid directly (out), petty cash funding
 * (out of the company into a float) and petty cash returns (back in), and labour batches marked
 * paid directly in Phase 6 (out). Spending from a float is not a company cash movement: the cash
 * left when the float was funded.
 *
 * Outstanding receivables / payables are derived from the documents and their approved
 * allocations (PayableService).
 */
class CashFlowService
{
    public const TYPES = ['receipt', 'payment', 'expense', 'petty_cash_funding', 'petty_cash_return', 'labour_direct'];

    public function __construct(private readonly PayableService $payables) {}

    /**
     * @param  list<int>  $projectIds  projects visible to the user
     * @param  array<string, mixed>  $filters  project_id, from, to, type, party, mode
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(array $projectIds, array $filters): Collection
    {
        $projectIds = filled($filters['project_id'] ?? null)
            ? array_values(array_intersect($projectIds, [(int) $filters['project_id']]))
            : $projectIds;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $type = $filters['type'] ?? null;
        $mode = $filters['mode'] ?? null;
        $party = trim((string) ($filters['party'] ?? ''));
        $rows = collect();

        if (! $type || in_array($type, ['receipt', 'payment'], true)) {
            Payment::query()->whereIn('project_id', $projectIds)->where('status', PaymentStatus::Approved)
                ->when($type, fn ($q) => $q->where('direction', $type))
                ->when($mode, fn ($q) => $q->where('mode', $mode))
                ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
                ->with('project:id,code,name')
                ->get()
                ->each(function (Payment $p) use ($rows) {
                    $rows->push([
                        'date' => $p->payment_date->toDateString(),
                        'type' => $p->direction->value,
                        'project' => $p->project?->code,
                        'project_id' => $p->project_id,
                        'document' => $p->payment_number,
                        'party' => $p->partyName(),
                        'party_type' => $p->party_type->label(),
                        'mode' => $p->mode->label(),
                        'reference' => $p->bank_reference,
                        'inflow' => $p->direction->value === 'receipt' ? (string) $p->amount : null,
                        'outflow' => $p->direction->value === 'payment' ? (string) $p->amount : null,
                        'url' => route('projects.payments.show', [$p->project_id, $p->id]),
                    ]);
                });
        }

        if (! $type || $type === 'expense') {
            Expense::query()->whereIn('project_id', $projectIds)->where('status', ExpenseStatus::Paid)
                ->where('payment_mode', '!=', PaymentMode::PettyCash)
                ->when($mode, fn ($q) => $q->where('payment_mode', $mode))
                ->when($from, fn ($q) => $q->whereDate('paid_on', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('paid_on', '<=', $to))
                ->with(['project:id,code', 'vendor:id,name'])
                ->get()
                ->each(function (Expense $e) use ($rows) {
                    $rows->push([
                        'date' => $e->paid_on->toDateString(),
                        'type' => 'expense',
                        'project' => $e->project?->code,
                        'project_id' => $e->project_id,
                        'document' => $e->expense_number,
                        'party' => $e->vendor?->name ?? $e->payee_name,
                        'party_type' => $e->vendor ? 'Vendor' : 'Payee',
                        'mode' => $e->payment_mode->label(),
                        'reference' => $e->payment_reference,
                        'inflow' => null,
                        'outflow' => (string) $e->total_amount,
                        'url' => route('projects.expenses.show', [$e->project_id, $e->id]),
                    ]);
                });
        }

        if ((! $type || in_array($type, ['petty_cash_funding', 'petty_cash_return'], true)) && ! $mode) {
            PettyCashTransaction::query()
                ->whereIn('type', [PettyCashTxnType::FundIn, PettyCashTxnType::ReturnOut])
                ->when($type === 'petty_cash_funding', fn ($q) => $q->where('type', PettyCashTxnType::FundIn))
                ->when($type === 'petty_cash_return', fn ($q) => $q->where('type', PettyCashTxnType::ReturnOut))
                ->whereHas('account', fn ($q) => $q->whereIn('project_id', $projectIds))
                ->when($from, fn ($q) => $q->whereDate('txn_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('txn_date', '<=', $to))
                ->with(['account:id,project_id,name,holder_user_id', 'account.project:id,code', 'account.holder:id,name'])
                ->get()
                ->each(function (PettyCashTransaction $t) use ($rows) {
                    $funding = $t->type === PettyCashTxnType::FundIn;
                    // Funding leaves the company (outflow); a return comes back (inflow). Reversal rows carry their sign.
                    $rows->push([
                        'date' => $t->txn_date->toDateString(),
                        'type' => $funding ? 'petty_cash_funding' : 'petty_cash_return',
                        'project' => $t->account?->project?->code,
                        'project_id' => $t->account?->project_id,
                        'document' => $t->account?->name,
                        'party' => $t->account?->holder?->name,
                        'party_type' => 'Petty cash holder',
                        'mode' => 'Petty cash',
                        'reference' => $t->remarks,
                        'inflow' => $funding ? null : (string) $t->amount,
                        'outflow' => $funding ? (string) $t->amount : null,
                        'url' => route('projects.petty-cash.show', [$t->account?->project_id, $t->petty_cash_account_id]),
                    ]);
                });
        }

        if ((! $type || $type === 'labour_direct') && ! $mode) {
            LabourPayment::query()->whereIn('project_id', $projectIds)->where('status', LabourPaymentStatus::Paid)
                ->where('paid_amount', 0)
                ->when($from, fn ($q) => $q->whereDate('paid_on', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('paid_on', '<=', $to))
                ->with('project:id,code')
                ->get()
                ->each(function (LabourPayment $l) use ($rows) {
                    $rows->push([
                        'date' => $l->paid_on?->toDateString(),
                        'type' => 'labour_direct',
                        'project' => $l->project?->code,
                        'project_id' => $l->project_id,
                        'document' => $l->payment_number,
                        'party' => 'Labour batch',
                        'party_type' => 'Labour',
                        'mode' => 'Recorded in labour payments',
                        'reference' => $l->payment_reference,
                        'inflow' => null,
                        'outflow' => (string) $l->total_net,
                        'url' => route('projects.labour-payments.show', [$l->project_id, $l->id]),
                    ]);
                });
        }

        if ($party !== '') {
            $needle = mb_strtolower($party);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['party']), $needle));
        }

        return $rows->sortBy([['date', 'desc'], ['document', 'desc']])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{inflow: string, outflow: string, net: string}
     */
    public function totals(Collection $entries): array
    {
        $in = Decimal::sum($entries->pluck('inflow')->filter()->all());
        $out = Decimal::sum($entries->pluck('outflow')->filter()->all());

        return ['inflow' => $in->toMoney(), 'outflow' => $out->toMoney(), 'net' => $in->minus($out)->toMoney()];
    }

    /**
     * Receivables (certified client bills) and payables (approved vendor bills, certified
     * subcontractor bills, approved labour batches) with an outstanding balance.
     *
     * @param  list<int>  $projectIds
     * @return array{receivables: list<array<string, mixed>>, payables: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function outstanding(array $projectIds, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $receivables = ClientInvoice::query()->whereIn('project_id', $projectIds)
            ->whereIn('status', [ClientInvoiceStatus::Certified, ClientInvoiceStatus::PartiallyPaid, ClientInvoiceStatus::Paid])
            ->with(['project:id,code', 'client:id,company_name'])->get()
            ->map(fn (ClientInvoice $i) => $this->row($i, $i->invoice_number, $i->client?->company_name, 'Client', $i->invoice_date->toDateString(), $asOf,
                route('projects.ra-bills.show', [$i->project_id, $i->id])))
            ->filter(fn ($r) => Decimal::of($r['outstanding'])->isPositive())->values();

        $vendor = VendorBill::query()->whereIn('project_id', $projectIds)->whereIn('status', VendorBillStatus::approvedStates())
            ->with(['project:id,code', 'vendor:id,name'])->get()
            ->map(fn (VendorBill $b) => $this->row($b, "{$b->bill_number} / {$b->vendor_invoice_no}", $b->vendor?->name, 'Vendor',
                ($b->due_date ?? $b->vendor_invoice_date)->toDateString(), $asOf, route('projects.vendor-bills.show', [$b->project_id, $b->id])));
        $sub = SubcontractorBill::query()->whereIn('project_id', $projectIds)->whereIn('status', SubcontractorBillStatus::certifiedStates())
            ->with(['project:id,code', 'subcontractor:id,name'])->get()
            ->map(fn (SubcontractorBill $b) => $this->row($b, $b->bill_number, $b->subcontractor?->name, 'Subcontractor',
                $b->bill_date->toDateString(), $asOf, route('projects.subcontractor-bills.show', [$b->project_id, $b->id])));
        $labour = LabourPayment::query()->whereIn('project_id', $projectIds)->where('status', LabourPaymentStatus::Approved)
            ->with('project:id,code')->get()
            ->map(fn (LabourPayment $l) => $this->row($l, $l->payment_number, 'Labour batch', 'Labour',
                $l->period_to->toDateString(), $asOf, route('projects.labour-payments.show', [$l->project_id, $l->id])));

        $payables = $vendor->concat($sub)->concat($labour)->filter(fn ($r) => Decimal::of($r['outstanding'])->isPositive())->values();

        return [
            'receivables' => $receivables->all(),
            'payables' => $payables->all(),
            'totals' => [
                'receivable' => Decimal::sum($receivables->pluck('outstanding')->all())->toMoney(),
                'payable' => Decimal::sum($payables->pluck('outstanding')->all())->toMoney(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Model $doc, ?string $number, ?string $party, string $partyType, string $date, string $asOf, string $url): array
    {
        $due = $this->payables->due($doc);
        $settled = $this->payables->settled($doc);
        $age = max(0, (int) floor((strtotime($asOf) - strtotime($date)) / 86400));

        return [
            'project' => $doc->project?->code,
            'project_id' => $doc->project_id,
            'document' => $number,
            'party' => $party,
            'party_type' => $partyType,
            'date' => $date,
            'due' => $due->toMoney(),
            'settled' => $settled->toMoney(),
            'outstanding' => $due->minus($settled)->toMoney(),
            'age_days' => $age,
            'bucket' => match (true) {
                $age <= 30 => '0-30',
                $age <= 60 => '31-60',
                $age <= 90 => '61-90',
                default => '90+',
            },
            'url' => $url,
        ];
    }
}

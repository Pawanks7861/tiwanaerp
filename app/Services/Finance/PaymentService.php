<?php

namespace App\Services\Finance;

use App\Enums\Finance\PaymentMode;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentAllocation;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified receipts (from the project's client, RCPT-) and payments (to vendors, subcontractors
 * and labour batches, PAY-): draft → approved → cancelled. Maker-checker: payments.record drafts,
 * a different user with payments.approve approves (direct workflow, not the approval engine).
 *
 * Allocations settle payables of the same party and project: Σ allocations ≤ amount, each
 * allocation ≤ the payable's outstanding (re-checked under row locks at approval). The remainder
 * is an on-account advance (a labour batch payment must be fully allocated). Only approved
 * payments count; approving or cancelling rebuilds every affected cache from the allocations.
 * No payment ever writes the project cost ledger.
 */
class PaymentService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PayableService $payables,
        private readonly ClientInvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $data  party_type, party_id, payment_date, mode, bank_reference, amount, tds_amount, remarks, allocations[] (payable_id, amount)
     */
    public function create(Project $project, array $data): Payment
    {
        return DB::transaction(function () use ($project, $data) {
            $party = PaymentPartyType::tryFrom((string) ($data['party_type'] ?? ''))
                ?? throw ValidationException::withMessages(['party_type' => 'Choose who the money is from or to.']);
            $partyId = $this->party($project, $party, $data['party_id'] ?? null);

            $payment = new Payment;
            $payment->forceFill([
                'project_id' => $project->id,
                'payment_number' => $this->numbers->next($party->direction()->numberingType(), $project),
                'direction' => $party->direction(),
                'party_type' => $party,
                'party_id' => $partyId,
                'status' => PaymentStatus::Draft,
                ...$this->header($data),
            ])->save();
            $this->writeAllocations($payment, $project, $data['allocations'] ?? []);

            return $payment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->forceFill($this->header($data))->save();
            $this->writeAllocations($locked, $locked->project, $data['allocations'] ?? []);

            return $locked;
        });
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            PaymentAllocation::query()->where('payment_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    public function approve(Payment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Draft) {
                throw ValidationException::withMessages(['payment' => 'Only a draft can be approved.']);
            }
            if ((int) $locked->created_by === (int) $user->id) {
                throw ValidationException::withMessages(['payment' => 'A payment cannot be approved by the person who recorded it.']);
            }
            if (! CompanyPermission::check($user, (int) $locked->company_id, 'payments.approve')) {
                throw ValidationException::withMessages(['payment' => 'Approving payments needs the payments.approve permission.']);
            }

            $payables = $this->lockPayables($locked);
            foreach ($locked->allocations()->get() as $allocation) {
                $payable = $payables[$allocation->payable_type.':'.$allocation->payable_id];
                if (! $this->payables->isSettleable($payable)) {
                    throw ValidationException::withMessages(['payment' => "{$this->payables->label($payable)} can no longer take a payment."]);
                }
                $outstanding = $this->payables->outstanding($payable);
                if (Decimal::of($allocation->amount)->greaterThan($outstanding)) {
                    throw ValidationException::withMessages(['payment' => "Allocation of {$allocation->amount} to {$this->payables->label($payable)} exceeds its outstanding {$outstanding->toMoney()}."]);
                }
            }

            $locked->forceFill(['status' => PaymentStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()])->save();
            foreach ($payables as $payable) {
                $this->payables->refresh($payable);
            }
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Withdraws an approved payment: its allocations stop counting and every affected cache is
     * rebuilt. A client receipt whose unallocated part was recovered as advance on an RA bill
     * cannot be cancelled.
     */
    public function cancel(Payment $payment, User $user, string $reason): void
    {
        DB::transaction(function () use ($payment, $user, $reason) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Approved) {
                throw ValidationException::withMessages(['payment' => 'Only an approved payment can be cancelled.']);
            }
            $payables = $this->lockPayables($locked);

            $locked->forceFill([
                'status' => PaymentStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            if ($locked->party_type === PaymentPartyType::Client) {
                $balance = $this->invoices->advanceBalance($locked->project_id, (int) $locked->party_id);
                if ($balance->isNegative()) {
                    throw ValidationException::withMessages(['payment' => 'Part of this receipt has been recovered as advance on an RA bill, so it cannot be cancelled.']);
                }
            }
            foreach ($payables as $payable) {
                $this->payables->refresh($payable);
            }
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Open payables of a party in a project with their outstanding (for the allocation form).
     *
     * @return list<array<string, mixed>>
     */
    public function openPayables(Project $project, PaymentPartyType $party, int $partyId, ?Payment $current = null): array
    {
        $class = $this->payables->classFor($party->payableType());
        $current?->loadMissing('allocations');
        $allocated = $current ? $current->allocations->pluck('amount', 'payable_id') : collect();

        return $class::query()->where('project_id', $project->id)->latest('id')->get()
            ->filter(fn (Model $p) => $this->payables->belongsToParty($p, $party, $partyId) && $this->payables->isSettleable($p))
            ->map(fn (Model $p) => [
                'id' => $p->getKey(),
                'label' => $this->payables->label($p),
                'due' => $this->payables->due($p)->toMoney(),
                'settled' => $this->payables->settled($p)->toMoney(),
                'outstanding' => $this->payables->outstanding($p)->toMoney(),
                'allocated' => (string) ($allocated[$p->getKey()] ?? ''),
            ])
            ->filter(fn (array $row) => Decimal::of($row['outstanding'])->isPositive() || $row['allocated'] !== '')
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $mode = PaymentMode::tryFrom((string) ($data['mode'] ?? ''));
        if ($mode === null || $mode === PaymentMode::PettyCash) {
            throw ValidationException::withMessages(['mode' => 'Choose cash, bank transfer, cheque, UPI or card.']);
        }
        $amount = $this->amount($data['amount'] ?? null, 'amount', required: true);
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        return [
            'payment_date' => $data['payment_date'],
            'mode' => $mode,
            'bank_reference' => $data['bank_reference'] ?? null,
            'amount' => $amount->toMoney(),
            'tds_amount' => $this->amount($data['tds_amount'] ?? null, 'tds_amount')->toMoney(),
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function party(Project $project, PaymentPartyType $party, mixed $id): int
    {
        $id = is_numeric($id) ? (int) $id : 0;
        $found = match ($party) {
            PaymentPartyType::Client => $project->client_id !== null && (int) $project->client_id === $id
                && Client::query()->whereKey($id)->exists(),
            PaymentPartyType::Vendor => Vendor::query()->whereKey($id)->exists(),
            PaymentPartyType::Subcontractor => Subcontractor::query()->whereKey($id)->exists(),
            PaymentPartyType::LabourPayment => LabourPayment::query()->where('project_id', $project->id)->whereKey($id)->exists(),
        };
        if (! $found) {
            throw ValidationException::withMessages(['party_id' => $party === PaymentPartyType::Client
                ? 'Receipts are taken from the client linked to this project.'
                : 'Choose a valid party.']);
        }

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  payable_id, amount
     */
    private function writeAllocations(Payment $payment, Project $project, array $rows): void
    {
        PaymentAllocation::query()->where('payment_id', $payment->id)->delete();

        $total = Decimal::zero();
        $seen = [];
        foreach (array_values($rows) as $index => $row) {
            $key = "allocations.{$index}";
            $amount = $this->amount($row['amount'] ?? null, "{$key}.amount");
            if ($amount->isZero()) {
                continue;
            }
            $payable = $this->payables->resolveForAllocation($project, $payment->party_type, (int) $payment->party_id, (int) ($row['payable_id'] ?? 0), "{$key}.payable_id");
            if (isset($seen[$payable->getKey()])) {
                throw ValidationException::withMessages(["{$key}.payable_id" => 'Each document can be allocated once per payment.']);
            }
            $seen[$payable->getKey()] = true;
            $outstanding = $this->payables->outstanding($payable);
            if ($amount->greaterThan($outstanding)) {
                throw ValidationException::withMessages(["{$key}.amount" => "Only {$outstanding->toMoney()} is outstanding on {$this->payables->label($payable)}."]);
            }
            $total = $total->plus($amount);

            (new PaymentAllocation)->forceFill([
                'payment_id' => $payment->id,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'amount' => $amount->toMoney(),
            ])->save();
        }

        if ($total->greaterThan($payment->amount)) {
            throw ValidationException::withMessages(['allocations' => "Allocations ({$total->toMoney()}) exceed the amount ({$payment->amount})."]);
        }
        if ($payment->party_type === PaymentPartyType::LabourPayment && ! $total->equals($payment->amount)) {
            throw ValidationException::withMessages(['allocations' => 'A labour batch payment must be allocated in full to the batch.']);
        }
    }

    /**
     * The payment's payables, locked in a fixed order.
     *
     * @return array<string, Model>
     */
    private function lockPayables(Payment $payment): array
    {
        $locked = [];
        foreach ($payment->allocations()->reorder()->orderBy('payable_type')->orderBy('payable_id')->get() as $allocation) {
            $class = $this->payables->classFor($allocation->payable_type);
            $locked[$allocation->payable_type.':'.$allocation->payable_id] = $class::query()->withTrashed()
                ->whereKey($allocation->payable_id)->lockForUpdate()->firstOrFail();
        }

        return $locked;
    }
}

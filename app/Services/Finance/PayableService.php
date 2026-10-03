<?php

namespace App\Services\Finance;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\RetentionReleaseStatus;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\PaymentAllocation;
use App\Models\Finance\RetentionRelease;
use App\Models\Finance\VendorBill;
use App\Models\Labour\LabourPayment;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The four documents a receipt / payment can settle: client RA bills, vendor bills,
 * subcontractor bills and labour payment batches.
 *
 * due = net payable (+ retention released by approved releases, for client and subcontractor
 * bills); settled = Σ allocations of approved payments. The cached paid / received amount and
 * the paid statuses are always rebuilt from those sums (never incremented).
 */
class PayableService
{
    /**
     * The payable, locked, after checking it belongs to the project and the party and can take
     * a payment.
     */
    public function resolveForAllocation(Project $project, PaymentPartyType $party, int $partyId, int $payableId, string $key): Model
    {
        $class = $this->classFor($party->payableType());
        /** @var Model|null $payable */
        $payable = $class::query()->where('project_id', $project->id)->whereKey($payableId)->lockForUpdate()->first();
        if ($payable === null || ! $this->belongsToParty($payable, $party, $partyId)) {
            throw ValidationException::withMessages([$key => 'This document does not belong to the chosen party in this project.']);
        }
        if (! $this->isSettleable($payable)) {
            throw ValidationException::withMessages([$key => "{$this->label($payable)} is not certified / approved for payment, or is already settled outside Finance."]);
        }

        return $payable;
    }

    public function classFor(string $payableType): string
    {
        return match ($payableType) {
            'client_invoice' => ClientInvoice::class,
            'vendor_bill' => VendorBill::class,
            'subcontractor_bill' => SubcontractorBill::class,
            'labour_payment' => LabourPayment::class,
            default => throw new LogicException("Unknown payable type {$payableType}."),
        };
    }

    public function belongsToParty(Model $payable, PaymentPartyType $party, int $partyId): bool
    {
        return match (true) {
            $payable instanceof ClientInvoice => $party === PaymentPartyType::Client && (int) $payable->client_id === $partyId,
            $payable instanceof VendorBill => $party === PaymentPartyType::Vendor && (int) $payable->vendor_id === $partyId,
            $payable instanceof SubcontractorBill => $party === PaymentPartyType::Subcontractor && (int) $payable->subcontractor_id === $partyId,
            $payable instanceof LabourPayment => $party === PaymentPartyType::LabourPayment && (int) $payable->id === $partyId,
            default => false,
        };
    }

    public function isSettleable(Model $payable): bool
    {
        return match (true) {
            $payable instanceof ClientInvoice => $payable->status->isCertified(),
            $payable instanceof VendorBill => $payable->status->isApproved(),
            $payable instanceof SubcontractorBill => $payable->status->isCertified(),
            // A batch marked paid directly (Phase 6) without Finance payments is closed.
            $payable instanceof LabourPayment => $payable->status === LabourPaymentStatus::Approved
                || ($payable->status === LabourPaymentStatus::Paid && Decimal::of($payable->paid_amount)->isPositive()),
            default => false,
        };
    }

    public function due(Model $payable): Decimal
    {
        return match (true) {
            $payable instanceof ClientInvoice => Decimal::of($payable->net_payable)->plus($this->releasedRetention($payable)),
            $payable instanceof VendorBill => Decimal::of($payable->net_payable),
            $payable instanceof SubcontractorBill => Decimal::of($payable->net_payable)->plus($this->releasedRetention($payable)),
            $payable instanceof LabourPayment => Decimal::of($payable->total_net),
            default => throw new LogicException('Not a payable.'),
        };
    }

    public function settled(Model $payable, ?int $exceptPaymentId = null): Decimal
    {
        return Decimal::sum(PaymentAllocation::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::Approved)
                ->when($exceptPaymentId, fn ($p) => $p->whereKeyNot($exceptPaymentId)))
            ->pluck('amount')->all());
    }

    public function outstanding(Model $payable, ?int $exceptPaymentId = null): Decimal
    {
        return $this->due($payable)->minus($this->settled($payable, $exceptPaymentId));
    }

    /**
     * Retention released (approved releases) on a client or subcontractor bill.
     */
    public function releasedRetention(Model $bill, bool $includePending = false, ?int $exceptReleaseId = null): Decimal
    {
        $statuses = $includePending ? [RetentionReleaseStatus::Approved, RetentionReleaseStatus::Submitted] : [RetentionReleaseStatus::Approved];

        return Decimal::sum(RetentionRelease::query()
            ->where('releasable_type', $bill->getMorphClass())
            ->where('releasable_id', $bill->getKey())
            ->whereIn('status', $statuses)
            ->when($exceptReleaseId, fn ($q) => $q->whereKeyNot($exceptReleaseId))
            ->pluck('amount')->all());
    }

    /**
     * Rebuilds the paid / received cache and the paid status from approved allocations.
     */
    public function refresh(Model $payable): void
    {
        $settled = $this->settled($payable);
        $due = $this->due($payable);
        $full = $settled->greaterThanOrEqual($due);

        if ($payable instanceof ClientInvoice) {
            $status = ! $payable->status->isCertified() ? $payable->status
                : ($settled->isZero() ? ClientInvoiceStatus::Certified : ($full ? ClientInvoiceStatus::Paid : ClientInvoiceStatus::PartiallyPaid));
            $payable->forceFill(['received_amount' => $settled->toMoney(), 'status' => $status])->save();

            return;
        }
        if ($payable instanceof VendorBill) {
            $status = ! $payable->status->isApproved() ? $payable->status
                : ($settled->isZero() ? VendorBillStatus::Approved : ($full ? VendorBillStatus::Paid : VendorBillStatus::PartiallyPaid));
            $payable->forceFill(['paid_amount' => $settled->toMoney(), 'status' => $status])->save();

            return;
        }
        if ($payable instanceof SubcontractorBill) {
            $status = ! $payable->status->isCertified() ? $payable->status
                : ($settled->isZero() ? SubcontractorBillStatus::Certified : ($full ? SubcontractorBillStatus::Paid : SubcontractorBillStatus::PartiallyPaid));
            $payable->forceFill(['paid_amount' => $settled->toMoney(), 'status' => $status])->save();

            return;
        }
        if ($payable instanceof LabourPayment) {
            $this->refreshLabourBatch($payable, $settled, $settled->isPositive() && $settled->greaterThanOrEqual($due));

            return;
        }

        throw new LogicException('Not a payable.');
    }

    public function label(Model $payable): string
    {
        return match (true) {
            $payable instanceof ClientInvoice => $payable->displayNumber(),
            $payable instanceof VendorBill => "{$payable->bill_number} ({$payable->vendor_invoice_no})",
            $payable instanceof SubcontractorBill => $payable->bill_number,
            $payable instanceof LabourPayment => $payable->payment_number,
            default => (string) $payable->getKey(),
        };
    }

    /**
     * A batch settled through Finance becomes paid once fully allocated, stamped with the last
     * approved payment; when an allocation is cancelled it returns to approved.
     */
    private function refreshLabourBatch(LabourPayment $batch, Decimal $settled, bool $full): void
    {
        $attributes = ['paid_amount' => $settled->toMoney()];
        if ($full && $batch->status === LabourPaymentStatus::Approved) {
            $last = PaymentAllocation::query()->where('payable_type', 'labour_payment')->where('payable_id', $batch->id)
                ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::Approved))
                ->with('payment:id,payment_number,payment_date,approved_by')
                ->latest('id')->first()?->payment;
            $attributes += [
                'status' => LabourPaymentStatus::Paid,
                'paid_by' => $last?->approved_by,
                'paid_at' => now(),
                'paid_on' => $last?->payment_date?->toDateString(),
                'payment_reference' => $last?->payment_number,
            ];
        } elseif (! $full && $batch->status === LabourPaymentStatus::Paid) {
            $attributes += ['status' => LabourPaymentStatus::Approved, 'paid_by' => null, 'paid_at' => null, 'paid_on' => null, 'payment_reference' => null];
        }

        $batch->forceFill($attributes)->save();
    }
}

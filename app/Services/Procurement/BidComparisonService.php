<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\RfqStatus;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqVendor;
use App\Models\Procurement\VendorQuotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The user chooses the winning quotation and records the basis and justification; nothing is
 * auto-selected. Approval is a direct action guarded by bid_comparison.approve and segregation of
 * duties (approver ≠ submitter); no approval workflow is configured for comparisons.
 */
class BidComparisonService
{
    public function __construct(private readonly RfqService $rfqs) {}

    public function forRfq(Rfq $rfq): BidComparison
    {
        return DB::transaction(function () use ($rfq) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            $existing = BidComparison::query()->where('rfq_id', $rfq->id)->first();
            if ($existing) {
                return $existing;
            }

            if ($rfq->status !== RfqStatus::QuotesReceived) {
                throw ValidationException::withMessages(['comparison' => 'Record at least one quotation before comparing bids.']);
            }

            return tap((new BidComparison)->forceFill(['rfq_id' => $rfq->id, 'status' => BidComparisonStatus::Draft]))->save();
        });
    }

    /**
     * @param  array{selected_vendor_quotation_id: int|string|null, selection_basis: string|null, justification: string|null}  $data
     */
    public function save(BidComparison $comparison, array $data): BidComparison
    {
        return DB::transaction(function () use ($comparison, $data) {
            $comparison = BidComparison::query()->whereKey($comparison->id)->lockForUpdate()->firstOrFail();
            $comparison->assertEditable();

            $quotationId = $data['selected_vendor_quotation_id'] ?? null;
            if ($quotationId !== null) {
                $this->assertQuotationOfRfq((int) $quotationId, $comparison->rfq_id);
            }

            $comparison->forceFill([
                'selected_vendor_quotation_id' => $quotationId !== null ? (int) $quotationId : null,
                'selection_basis' => $data['selection_basis'] ?? null,
                'justification' => $data['justification'] ?? null,
            ])->save();

            return $comparison;
        });
    }

    public function submit(BidComparison $comparison, User $user): void
    {
        DB::transaction(function () use ($comparison, $user) {
            $comparison = BidComparison::query()->whereKey($comparison->id)->lockForUpdate()->firstOrFail();
            $comparison->assertEditable();

            if ($comparison->selected_vendor_quotation_id === null || $comparison->selection_basis === null || blank($comparison->justification)) {
                throw ValidationException::withMessages(['comparison' => 'Select a vendor and record the selection basis and justification before submitting.']);
            }
            $this->assertQuotationOfRfq($comparison->selected_vendor_quotation_id, $comparison->rfq_id);

            $rfq = Rfq::query()->whereKey($comparison->rfq_id)->lockForUpdate()->firstOrFail();
            if ($rfq->status !== RfqStatus::QuotesReceived) {
                throw ValidationException::withMessages(['comparison' => 'The RFQ is not open for evaluation.']);
            }

            $comparison->forceFill([
                'status' => BidComparisonStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'rejection_reason' => null,
            ])->save();
            $rfq->forceFill(['status' => RfqStatus::Evaluated])->save();
        });
    }

    public function approve(BidComparison $comparison, User $user): void
    {
        DB::transaction(function () use ($comparison, $user) {
            $comparison = $this->lockSubmitted($comparison);
            $this->assertNotSubmitter($comparison, $user);
            $this->assertQuotationOfRfq($comparison->selected_vendor_quotation_id, $comparison->rfq_id);

            $comparison->forceFill([
                'status' => BidComparisonStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            VendorQuotation::query()->where('rfq_id', $comparison->rfq_id)->get()->each(function (VendorQuotation $q) use ($comparison) {
                $q->forceFill(['is_selected' => $q->id === $comparison->selected_vendor_quotation_id])->save();
            });
        });
    }

    public function reject(BidComparison $comparison, User $user, string $reason): void
    {
        DB::transaction(function () use ($comparison, $user, $reason) {
            $comparison = $this->lockSubmitted($comparison);
            $this->assertNotSubmitter($comparison, $user);

            $comparison->forceFill(['status' => BidComparisonStatus::Rejected, 'rejection_reason' => $reason])->save();

            $rfq = Rfq::query()->whereKey($comparison->rfq_id)->lockForUpdate()->firstOrFail();
            if ($rfq->status === RfqStatus::Evaluated) {
                $rfq->forceFill(['status' => RfqStatus::QuotesReceived])->save();
            }
        });
    }

    /**
     * The quotation must belong to this RFQ and come from a vendor invited to it.
     */
    private function assertQuotationOfRfq(int $quotationId, int $rfqId): void
    {
        $quotation = VendorQuotation::query()->whereKey($quotationId)->where('rfq_id', $rfqId)->first();
        $invited = $quotation !== null
            && RfqVendor::query()->where('rfq_id', $rfqId)->where('vendor_id', $quotation->vendor_id)->exists();

        if (! $invited) {
            throw ValidationException::withMessages(['selected_vendor_quotation_id' => 'Select a quotation submitted for this RFQ.']);
        }
    }

    private function lockSubmitted(BidComparison $comparison): BidComparison
    {
        $comparison = BidComparison::query()->whereKey($comparison->id)->lockForUpdate()->firstOrFail();
        if ($comparison->status !== BidComparisonStatus::Submitted) {
            throw ValidationException::withMessages(['comparison' => 'Only submitted comparisons can be approved or rejected.']);
        }

        return $comparison;
    }

    private function assertNotSubmitter(BidComparison $comparison, User $user): void
    {
        if (! config('approvals.allow_self_approval') && (int) $comparison->submitted_by === (int) $user->id) {
            throw ValidationException::withMessages(['comparison' => 'You submitted this comparison, so another user must approve or reject it.']);
        }
    }
}

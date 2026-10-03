<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\RfqVendorStatus;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\RfqVendor;
use App\Models\Procurement\VendorQuotation;
use App\Models\Procurement\VendorQuotationItem;
use App\Services\Tax\GstCalculator;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records vendor offers. Lines are pre-filled from the RFQ items; quantities always come from the
 * RFQ and every amount is recomputed here, whatever the client sent.
 */
class VendorQuotationService
{
    public function __construct(
        private readonly GstCalculator $gst,
        private readonly RfqService $rfqs,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated header + items keyed by position
     */
    public function save(Rfq $rfq, array $data, ?VendorQuotation $quotation = null): VendorQuotation
    {
        return DB::transaction(function () use ($rfq, $data, $quotation) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! $rfq->status->acceptsQuotations()) {
                throw ValidationException::withMessages(['quotation' => 'Quotations can only be recorded after the RFQ is sent and before it is evaluated.']);
            }

            $vendorId = $quotation?->vendor_id ?? (int) $data['vendor_id'];
            $invitation = RfqVendor::query()->where('rfq_id', $rfq->id)->where('vendor_id', $vendorId)->first()
                ?? throw ValidationException::withMessages(['vendor_id' => 'This vendor was not invited to the RFQ.']);

            if ($quotation === null && VendorQuotation::query()->where('rfq_id', $rfq->id)->where('vendor_id', $vendorId)->exists()) {
                throw ValidationException::withMessages(['vendor_id' => 'This vendor has already quoted. Edit the existing quotation instead.']);
            }

            $quotation ??= tap(new VendorQuotation)->forceFill(['rfq_id' => $rfq->id, 'vendor_id' => $vendorId]);
            $quotation->setRelation('rfq', $rfq);
            $quotation->fill([
                'quotation_number' => $data['quotation_number'] ?? null,
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'delivery_days' => $data['delivery_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'warranty' => $data['warranty'] ?? null,
                'freight_amount' => Decimal::of((string) ($data['freight_amount'] ?? '0'))->toMoney(),
                'other_charges' => Decimal::of((string) ($data['other_charges'] ?? '0'))->toMoney(),
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $this->replaceLines($quotation, $rfq, $data['items']);

            $invitation->forceFill(['status' => RfqVendorStatus::Responded, 'responded_at' => $invitation->responded_at ?? now()])->save();
            $this->rfqs->refreshQuoteStatus($rfq);

            return $quotation;
        });
    }

    public function delete(VendorQuotation $quotation): void
    {
        DB::transaction(function () use ($quotation) {
            $quotation->assertEditable();
            $quotation->delete();

            RfqVendor::query()->where('rfq_id', $quotation->rfq_id)->where('vendor_id', $quotation->vendor_id)
                ->update(['status' => RfqVendorStatus::Sent->value, 'responded_at' => null]);
            $this->rfqs->refreshQuoteStatus($quotation->parentRfq());
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows  rfq_item_id, rate (null = not quoted), discount_percent, tax_rate_id, remarks
     */
    private function replaceLines(VendorQuotation $quotation, Rfq $rfq, array $rows): void
    {
        $rfqItems = RfqItem::query()->where('rfq_id', $rfq->id)->get()->keyBy('id');
        $taxRates = TaxRate::query()->active()->whereKey(array_filter(array_column($rows, 'tax_rate_id')))->get()->keyBy('id');

        VendorQuotationItem::query()->where('vendor_quotation_id', $quotation->id)->get()->each->delete();

        $lines = [];
        $seen = [];
        foreach (array_values($rows) as $index => $row) {
            $rfqItem = $rfqItems->get((int) $row['rfq_item_id'])
                ?? throw ValidationException::withMessages(["items.{$index}.rfq_item_id" => 'This line is not part of the RFQ.']);
            if (isset($seen[$rfqItem->id])) {
                throw ValidationException::withMessages(["items.{$index}.rfq_item_id" => 'Each RFQ item can only be quoted once.']);
            }
            $seen[$rfqItem->id] = true;

            if (($row['rate'] ?? null) === null || $row['rate'] === '') {
                continue;
            }

            $tax = null;
            if (! empty($row['tax_rate_id'])) {
                $tax = $taxRates->get((int) $row['tax_rate_id'])
                    ?? throw ValidationException::withMessages(["items.{$index}.tax_rate_id" => 'Select an active tax rate.']);
            }

            $rate = Decimal::of((string) $row['rate'])->toRate();
            $discount = Decimal::of((string) ($row['discount_percent'] ?? '0'))->round(Decimal::PERCENT_SCALE)->toString();
            $amounts = $this->gst->quotationLine($rfqItem->quantity, $rate, $discount, $tax);

            (new VendorQuotationItem)->forceFill([
                'vendor_quotation_id' => $quotation->id,
                'rfq_item_id' => $rfqItem->id,
                'quantity' => $rfqItem->quantity,
                'rate' => $rate,
                'discount_percent' => $discount,
                'tax_rate_id' => $tax?->id,
                'remarks' => $row['remarks'] ?? null,
                ...$amounts,
            ])->save();
            $lines[] = $amounts;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Enter a rate for at least one item.']);
        }

        $taxable = Decimal::sum(array_column($lines, 'taxable_amount'));
        $tax = Decimal::sum(array_column($lines, 'tax_amount'));
        $quotation->forceFill([
            'subtotal' => Decimal::sum(array_column($lines, 'base_amount'))->toMoney(),
            'discount_amount' => Decimal::sum(array_column($lines, 'discount_amount'))->toMoney(),
            'taxable_amount' => $taxable->toMoney(),
            'tax_amount' => $tax->toMoney(),
            'grand_total' => $taxable->plus($tax)->plus($quotation->freight_amount)->plus($quotation->other_charges)->toMoney(),
        ])->save();
    }
}

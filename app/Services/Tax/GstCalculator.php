<?php

namespace App\Services\Tax;

use App\Enums\Procurement\TaxType;
use App\Models\Masters\TaxRate;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * The only place that decides GST type and computes GST amounts (architecture H.8 tax type rule).
 *
 * Line: base = qty × rate → discount = base × discount% → taxable = base − discount →
 * CGST/SGST (intra) or IGST (inter) = taxable × rate%. Every amount is rounded to the paisa
 * (HALF_UP) per line; document totals are sums of the rounded lines.
 */
final class GstCalculator
{
    /**
     * Intra-state when the vendor's state equals the place of supply, otherwise inter-state.
     */
    public function taxType(?string $vendorState, ?string $placeOfSupply): TaxType
    {
        if (blank($vendorState)) {
            throw ValidationException::withMessages([
                'vendor_id' => 'The vendor has no GST state in the vendor master, so the GST type cannot be determined.',
            ]);
        }

        if (blank($placeOfSupply)) {
            throw ValidationException::withMessages(['place_of_supply_state' => 'Select the place of supply.']);
        }

        return $vendorState === $placeOfSupply ? TaxType::Intra : TaxType::Inter;
    }

    /**
     * @return array{base_amount: string, discount_amount: string, taxable_amount: string, cgst_rate: string, cgst_amount: string, sgst_rate: string, sgst_amount: string, igst_rate: string, igst_amount: string, amount: string}
     */
    public function line(string $quantity, string $rate, ?string $discountPercent, ?TaxRate $tax, TaxType $type): array
    {
        [$base, $discount, $taxable] = $this->taxableParts($quantity, $rate, $discountPercent);

        $cgstRate = $sgstRate = $igstRate = Decimal::zero();
        if ($tax !== null) {
            if ($type === TaxType::Intra) {
                $cgstRate = Decimal::of($tax->cgst_rate);
                $sgstRate = Decimal::of($tax->sgst_rate);
            } else {
                $igstRate = Decimal::of($tax->igst_rate);
            }
        }

        $cgst = $taxable->percentOf($cgstRate)->round();
        $sgst = $taxable->percentOf($sgstRate)->round();
        $igst = $taxable->percentOf($igstRate)->round();

        return [
            'base_amount' => $base->toMoney(),
            'discount_amount' => $discount->toMoney(),
            'taxable_amount' => $taxable->toMoney(),
            'cgst_rate' => $cgstRate->round(Decimal::PERCENT_SCALE)->toString(),
            'cgst_amount' => $cgst->toMoney(),
            'sgst_rate' => $sgstRate->round(Decimal::PERCENT_SCALE)->toString(),
            'sgst_amount' => $sgst->toMoney(),
            'igst_rate' => $igstRate->round(Decimal::PERCENT_SCALE)->toString(),
            'igst_amount' => $igst->toMoney(),
            'amount' => $taxable->plus($cgst)->plus($sgst)->plus($igst)->toMoney(),
        ];
    }

    /**
     * GST on an already-computed taxable amount (document-level tax such as a client RA bill).
     *
     * @return array{cgst_rate: string, cgst_amount: string, sgst_rate: string, sgst_amount: string, igst_rate: string, igst_amount: string, tax_amount: string, amount: string}
     */
    public function onAmount(string $taxable, ?TaxRate $tax, TaxType $type): array
    {
        $line = $this->line('1', Decimal::of($taxable)->round()->toMoney(), null, $tax, $type);
        $taxAmount = Decimal::sum([$line['cgst_amount'], $line['sgst_amount'], $line['igst_amount']]);

        return [
            'cgst_rate' => $line['cgst_rate'],
            'cgst_amount' => $line['cgst_amount'],
            'sgst_rate' => $line['sgst_rate'],
            'sgst_amount' => $line['sgst_amount'],
            'igst_rate' => $line['igst_rate'],
            'igst_amount' => $line['igst_amount'],
            'tax_amount' => $taxAmount->toMoney(),
            'amount' => $line['amount'],
        ];
    }

    /**
     * Purchase order totals. round_off brings the grand total to the nearest rupee.
     *
     * @param  iterable<array<string, string>>  $lines  results of line()
     * @return array<string, string>
     */
    public function orderTotals(iterable $lines, ?string $freight, ?string $otherCharges): array
    {
        $sums = array_fill_keys(['base_amount', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount'], Decimal::zero());
        foreach ($lines as $line) {
            foreach ($sums as $key => $total) {
                $sums[$key] = $total->plus($line[$key]);
            }
        }

        $freight = Decimal::of($freight)->round();
        $other = Decimal::of($otherCharges)->round();
        $beforeRounding = $sums['taxable_amount']->plus($sums['cgst_amount'])->plus($sums['sgst_amount'])
            ->plus($sums['igst_amount'])->plus($freight)->plus($other);
        $grand = $beforeRounding->round(0);

        return [
            'subtotal' => $sums['base_amount']->toMoney(),
            'discount_amount' => $sums['discount_amount']->toMoney(),
            'taxable_amount' => $sums['taxable_amount']->toMoney(),
            'cgst_amount' => $sums['cgst_amount']->toMoney(),
            'sgst_amount' => $sums['sgst_amount']->toMoney(),
            'igst_amount' => $sums['igst_amount']->toMoney(),
            'freight_amount' => $freight->toMoney(),
            'other_charges' => $other->toMoney(),
            'round_off' => $grand->minus($beforeRounding)->toMoney(),
            'grand_total' => $grand->toMoney(),
        ];
    }

    /**
     * Vendor quotation line: the place of supply is not decided yet, so GST is the total rate.
     *
     * @return array{base_amount: string, discount_amount: string, taxable_amount: string, tax_percent: string, tax_amount: string, amount: string}
     */
    public function quotationLine(string $quantity, string $rate, ?string $discountPercent, ?TaxRate $tax): array
    {
        [$base, $discount, $taxable] = $this->taxableParts($quantity, $rate, $discountPercent);
        $percent = $tax ? Decimal::of($tax->rate) : Decimal::zero();
        $taxAmount = $taxable->percentOf($percent)->round();

        return [
            'base_amount' => $base->toMoney(),
            'discount_amount' => $discount->toMoney(),
            'taxable_amount' => $taxable->toMoney(),
            'tax_percent' => $percent->round(Decimal::PERCENT_SCALE)->toString(),
            'tax_amount' => $taxAmount->toMoney(),
            'amount' => $taxable->plus($taxAmount)->toMoney(),
        ];
    }

    /**
     * @return array{0: Decimal, 1: Decimal, 2: Decimal} base, discount, taxable (rounded to the paisa)
     */
    private function taxableParts(string $quantity, string $rate, ?string $discountPercent): array
    {
        $base = Decimal::of($quantity)->times($rate)->round();
        $discount = $base->percentOf($discountPercent)->round();

        return [$base, $discount, $base->minus($discount)];
    }
}

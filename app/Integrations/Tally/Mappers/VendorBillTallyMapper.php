<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\CostHead;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\VendorBill;

/**
 * Approved vendor bill. This is the accounting event. The GRN is not posted again.
 *
 * Dr expense (by cost head) for taxable value and input GST.
 * Cr TDS payable and the vendor for the stored net payable.
 */
class VendorBillTallyMapper
{
    use BuildsVoucherLines;

    public function map(VendorBill $bill, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('vendor_bill', $bill->id);
        $vendor = $ledgers->party('vendor', (int) $bill->vendor_id, 'Vendor');
        $expense = $this->expenseLedger($bill->cost_head, $ledgers);
        $narration = mb_substr(
            $this->narration($bill->project?->code, $reference).' | Vendor inv: '.$bill->vendor_invoice_no,
            0,
            240,
        );
        $voucher = new TallyVoucher(
            voucherType: 'Purchase',
            date: $bill->vendor_invoice_date->toDateString(),
            number: $bill->bill_number,
            reference: $reference,
            narration: $narration,
            partyLedger: $vendor,
            lines: $this->lines([
                $this->debit($expense, $bill->subtotal),
                $this->debit($ledgers->system('gst_input_cgst', 'Input CGST'), $bill->cgst_amount),
                $this->debit($ledgers->system('gst_input_sgst', 'Input SGST'), $bill->sgst_amount),
                $this->debit($ledgers->system('gst_input_igst', 'Input IGST'), $bill->igst_amount),
                $this->credit($ledgers->system('tds_payable', 'TDS payable'), $bill->tds_amount),
                $this->credit($vendor, $bill->net_payable),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }

    private function expenseLedger(?CostHead $head, TallyLedgerMapper $ledgers): string
    {
        $key = match ($head) {
            CostHead::Labour => 'labour',
            CostHead::Equipment => 'equipment',
            CostHead::Subcontract => 'subcontract',
            CostHead::Overhead, CostHead::Other => 'expense',
            default => 'material',
        };

        return $ledgers->system($key, 'Purchase expense');
    }
}

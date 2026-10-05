<?php

namespace App\Integrations\Tally\Mappers;

use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Subcontract\SubcontractorBill;

/**
 * Certified subcontractor bill. Tax is the single stored tax amount (the bill does not split GST).
 * Retention, TDS and advance recovery use their own ledgers and are not folded into the party.
 */
class SubcontractBillTallyMapper
{
    use BuildsVoucherLines;

    public function map(SubcontractorBill $bill, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('subcontractor_bill', $bill->id);
        $party = $ledgers->party('subcontractor', (int) $bill->subcontractor_id, 'Subcontractor');
        $voucher = new TallyVoucher(
            voucherType: 'Journal',
            date: $bill->bill_date->toDateString(),
            number: $bill->bill_number,
            reference: $reference,
            narration: $this->narration($bill->project?->code, $reference),
            partyLedger: $party,
            lines: $this->lines([
                $this->debit($ledgers->system('subcontract', 'Subcontract expenses'), $bill->gross_amount),
                $this->debit($ledgers->system('gst_input', 'Input GST (single amount)'), $bill->tax_amount),
                $this->credit($ledgers->system('retention_payable', 'Retention payable'), $bill->retention_amount),
                $this->credit($ledgers->system('tds_payable', 'TDS payable'), $bill->tds_amount),
                $this->credit($ledgers->system('advance_subcontract', 'Subcontract advance'), $bill->advance_recovery),
                $this->credit($ledgers->system('other_deduction', 'Other deductions'), $bill->other_deductions),
                $this->credit($party, $bill->net_payable),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

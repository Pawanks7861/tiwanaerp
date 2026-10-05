<?php

namespace App\Integrations\Tally\Mappers;

use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Labour\LabourPayment;
use App\Support\Math\Decimal;

/**
 * Approved labour payment batch, as one voucher. Attendance rows are not sent.
 * Dr labour charges for gross plus OT. Cr deductions and labour payable for the stored net.
 * The later cash payment settles labour payable and does not post the wage again.
 */
class LabourPaymentTallyMapper
{
    use BuildsVoucherLines;

    public function map(LabourPayment $payment, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('labour_payment', $payment->id);
        $wages = Decimal::of((string) $payment->total_gross)->plus((string) $payment->total_ot)->toMoney();
        $voucher = new TallyVoucher(
            voucherType: 'Journal',
            date: $payment->period_to->toDateString(),
            number: $payment->payment_number,
            reference: $reference,
            narration: $this->narration($payment->project?->code, $reference),
            partyLedger: $ledgers->system('labour_payable', 'Labour payable'),
            lines: $this->lines([
                $this->debit($ledgers->system('labour', 'Labour charges'), $wages),
                $this->credit($ledgers->system('labour_deductions', 'Labour deductions'), $payment->total_deductions),
                $this->credit($ledgers->system('labour_payable', 'Labour payable'), $payment->total_net),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

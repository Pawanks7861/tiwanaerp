<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\Finance\PaymentMode;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\Payment;

/** Cash paid against an approved labour batch. Debits labour payable. Does not post wages again. */
class LabourSettlementTallyMapper
{
    use BuildsVoucherLines;

    public function map(Payment $payment, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('payment', $payment->id);
        $payable = $ledgers->system('labour_payable', 'Labour payable');
        $cash = $payment->mode === PaymentMode::Cash
            ? $ledgers->system('cash', 'Cash')
            : $ledgers->system('bank', 'Bank');
        $voucher = new TallyVoucher(
            voucherType: 'Payment',
            date: $payment->payment_date->toDateString(),
            number: $payment->payment_number,
            reference: $reference,
            narration: $this->narration($payment->project?->code, $reference),
            partyLedger: $payable,
            lines: $this->lines([
                $this->debit($payable, $payment->amount),
                $this->credit($cash, $payment->amount),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\Finance\PaymentMode;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\Payment;

/** Approved subcontract payment. Cash only; the certified bill already carried the expense. */
class SubcontractPaymentTallyMapper
{
    use BuildsVoucherLines;

    public function map(Payment $payment, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('payment', $payment->id);
        $party = $ledgers->party('subcontractor', (int) $payment->party_id, 'Subcontractor');
        $cash = $payment->mode === PaymentMode::Cash
            ? $ledgers->system('cash', 'Cash')
            : $ledgers->system('bank', 'Bank');
        $voucher = new TallyVoucher(
            voucherType: 'Payment',
            date: $payment->payment_date->toDateString(),
            number: $payment->payment_number,
            reference: $reference,
            narration: $this->narration($payment->project?->code, $reference),
            partyLedger: $party,
            lines: $this->lines([
                $this->debit($party, $payment->amount),
                $this->credit($cash, $payment->amount),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

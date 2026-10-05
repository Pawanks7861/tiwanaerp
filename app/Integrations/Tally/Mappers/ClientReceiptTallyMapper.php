<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\Finance\PaymentMode;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\Payment;

/**
 * Approved client receipt. Cash/bank is the stored receipt amount. TDS on the receipt, when stored,
 * is a separate debit so the client is credited for cash plus TDS and cash is not overstated.
 */
class ClientReceiptTallyMapper
{
    use BuildsVoucherLines;

    public function map(Payment $payment, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('payment', $payment->id);
        $client = $ledgers->party('client', (int) $payment->party_id, 'Client');
        $cash = $payment->mode === PaymentMode::Cash
            ? $ledgers->system('cash', 'Cash')
            : $ledgers->system('bank', 'Bank');
        $allocations = $payment->relationLoaded('allocations')
            ? $payment->allocations->pluck('payable_id')->implode(',')
            : '';
        $narration = $this->narration($payment->project?->code, $reference);
        if ($allocations !== '') {
            $narration = mb_substr($narration.' | Allocations: '.$allocations, 0, 240);
        }

        $voucher = new TallyVoucher(
            voucherType: 'Receipt',
            date: $payment->payment_date->toDateString(),
            number: $payment->payment_number,
            reference: $reference,
            narration: $narration,
            partyLedger: $client,
            lines: $this->lines([
                $this->debit($cash, $payment->amount),
                $this->debit($ledgers->system('tds_receivable', 'TDS receivable'), $payment->tds_amount),
                $this->credit($client, $this->money(\App\Support\Math\Decimal::of((string) $payment->amount)->plus((string) $payment->tds_amount))),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

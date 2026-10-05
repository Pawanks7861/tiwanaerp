<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\Finance\PettyCashTxnType;
use App\Integrations\Tally\TallyException;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\PettyCashTransaction;

/**
 * Petty-cash funding and returns are cash transfers. An expense_out row is not synced: the approved
 * expense posts Dr expense, Cr petty cash, so the same movement is not sent twice.
 */
class PettyCashTallyMapper
{
    use BuildsVoucherLines;

    public function map(PettyCashTransaction $transaction, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        if ($transaction->type === PettyCashTxnType::ExpenseOut) {
            throw new TallyException('Petty cash expenses are synced from the approved expense, not from the float ledger.');
        }

        $reference = $this->reference('petty_cash_transaction', $transaction->id);
        $float = $ledgers->system('petty_cash', 'Petty cash');
        $bank = $ledgers->system('bank', 'Bank');
        $funding = $transaction->type === PettyCashTxnType::FundIn;
        $project = $transaction->account?->project?->code;
        $voucher = new TallyVoucher(
            voucherType: 'Journal',
            date: $transaction->txn_date->toDateString(),
            number: 'PC-'.$transaction->id,
            reference: $reference,
            narration: $this->narration($project, $reference),
            partyLedger: $float,
            lines: $this->lines([
                $funding ? $this->debit($float, $transaction->amount) : $this->debit($bank, $transaction->amount),
                $funding ? $this->credit($bank, $transaction->amount) : $this->credit($float, $transaction->amount),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}

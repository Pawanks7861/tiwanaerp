<?php

namespace App\Integrations\Tally\Mappers;

use App\Enums\CostHead;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Integrations\Tally\MissingTallyMappingException;
use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\Expense;

/**
 * Approved expense. Petty cash and paid expenses credit cash, bank or the petty-cash float.
 * An approved expense that is not yet paid credits the vendor, or Expense payable when there is no vendor.
 * GST is the stored tax_amount on the single Input GST ledger. Project cost is not posted again.
 */
class ExpenseTallyMapper
{
    use BuildsVoucherLines;

    public function map(Expense $expense, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('expense', $expense->id);
        $expenseLedger = $this->expenseLedger($expense, $ledgers);
        $creditLedger = $this->creditLedger($expense, $ledgers);
        $paid = $expense->isPettyCash() || $expense->status === ExpenseStatus::Paid || $expense->paid_at !== null;
        $voucher = new TallyVoucher(
            voucherType: $paid ? 'Payment' : 'Journal',
            date: $expense->expense_date->toDateString(),
            number: $expense->expense_number,
            reference: $reference,
            narration: $this->narration($expense->project?->code, $reference),
            partyLedger: $creditLedger,
            lines: $this->lines([
                $this->debit($expenseLedger, $expense->amount),
                $this->debit($ledgers->system('gst_input', 'Input GST (single amount)'), $expense->tax_amount),
                $this->credit($creditLedger, $expense->total_amount),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }

    private function expenseLedger(Expense $expense, TallyLedgerMapper $ledgers): string
    {
        if ($expense->expense_category_id) {
            try {
                return $ledgers->party('expense_category', (int) $expense->expense_category_id, 'Expense category');
            } catch (MissingTallyMappingException) {
                // Fall through to the cost-head ledger.
            }
        }

        $key = match ($expense->cost_head) {
            CostHead::Labour => 'labour',
            CostHead::Equipment => 'equipment',
            CostHead::Subcontract => 'subcontract',
            CostHead::Material => 'material',
            default => 'expense',
        };

        return $ledgers->system($key, 'Expense');
    }

    private function creditLedger(Expense $expense, TallyLedgerMapper $ledgers): string
    {
        if ($expense->isPettyCash()) {
            return $ledgers->system('petty_cash', 'Petty cash');
        }
        if ($expense->status === ExpenseStatus::Paid || $expense->paid_at !== null) {
            return $expense->payment_mode === PaymentMode::Cash
                ? $ledgers->system('cash', 'Cash')
                : $ledgers->system('bank', 'Bank');
        }
        if ($expense->vendor_id) {
            return $ledgers->party('vendor', (int) $expense->vendor_id, 'Vendor');
        }

        return $ledgers->system('expense_payable', 'Expense payable');
    }
}

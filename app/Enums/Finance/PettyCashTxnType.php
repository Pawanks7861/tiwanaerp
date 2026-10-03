<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * Petty cash ledger movements. Balance = fund_in − expense_out − return_out (signed rows, so a
 * reversal row simply negates the row it cancels). Funding is a cash transfer, never a cost.
 */
enum PettyCashTxnType: string
{
    use HasOptions;

    case FundIn = 'fund_in';
    case ExpenseOut = 'expense_out';
    case ReturnOut = 'return_out';

    public function label(): string
    {
        return match ($this) {
            self::FundIn => 'Funded',
            self::ExpenseOut => 'Expense',
            self::ReturnOut => 'Returned',
        };
    }

    /** +1 adds to the float, −1 takes from it. */
    public function sign(): int
    {
        return $this === self::FundIn ? 1 : -1;
    }
}

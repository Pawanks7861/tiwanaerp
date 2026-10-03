<?php

namespace App\Services\Finance;

use App\Enums\Finance\PettyCashTxnType;
use App\Models\Finance\Expense;
use App\Models\Finance\PettyCashAccount;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Petty cash floats and their append-only ledger. Funding and returns move cash between the
 * company and the holder (never a cost); an approved petty cash expense drains the float once per
 * revision (posting_ref "expense:{id}:r{revision}:out"), and reversing that expense appends the
 * compensating row. The balance is always derived from the ledger under the account lock.
 */
class PettyCashService
{
    use ResolvesProjectRefs;

    /**
     * @param  array<string, mixed>  $data  name, holder_user_id, limit_amount
     */
    public function createAccount(Project $project, array $data): PettyCashAccount
    {
        return DB::transaction(function () use ($project, $data) {
            $holder = $this->holder($project, $data['holder_user_id'] ?? null);

            $account = new PettyCashAccount;
            $account->forceFill([
                'project_id' => $project->id,
                'holder_user_id' => $holder->id,
                'name' => trim((string) $data['name']),
                'limit_amount' => $this->amount($data['limit_amount'] ?? null, 'limit_amount')->toMoney(),
                'is_active' => true,
            ])->save();

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, limit_amount, is_active
     */
    public function updateAccount(PettyCashAccount $account, array $data): PettyCashAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $locked = PettyCashAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $limit = $this->amount($data['limit_amount'] ?? null, 'limit_amount');
            $balance = $this->balanceOf($locked);
            if (! $limit->isZero() && $limit->lessThan($balance)) {
                throw ValidationException::withMessages(['limit_amount' => 'The limit cannot be below the current balance ('.$balance->toMoney().').']);
            }

            $locked->forceFill([
                'name' => trim((string) $data['name']),
                'limit_amount' => $limit->toMoney(),
                'is_active' => (bool) ($data['is_active'] ?? $locked->is_active),
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data  amount, txn_date, remarks
     */
    public function fund(PettyCashAccount $account, User $user, array $data): PettyCashTransaction
    {
        return DB::transaction(function () use ($account, $user, $data) {
            $locked = PettyCashAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['amount' => 'This petty cash account is inactive.']);
            }
            $amount = $this->positive($data['amount'] ?? null);
            $after = $this->balanceOf($locked)->plus($amount);
            if (! Decimal::of($locked->limit_amount)->isZero() && $after->greaterThan($locked->limit_amount)) {
                throw ValidationException::withMessages(['amount' => "Funding would take the balance to {$after->toMoney()}, above the limit of {$locked->limit_amount}."]);
            }

            return $this->append($locked, PettyCashTxnType::FundIn, $amount, $data['txn_date'], 'fund:'.Str::uuid(), $data['remarks'] ?? null, $user->id);
        });
    }

    /**
     * Cash handed back by the holder.
     *
     * @param  array<string, mixed>  $data  amount, txn_date, remarks
     */
    public function returnCash(PettyCashAccount $account, User $user, array $data): PettyCashTransaction
    {
        return DB::transaction(function () use ($account, $user, $data) {
            $locked = PettyCashAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $amount = $this->positive($data['amount'] ?? null);
            $balance = $this->balanceOf($locked);
            if ($amount->greaterThan($balance)) {
                throw ValidationException::withMessages(['amount' => "Only {$balance->toMoney()} is in this float."]);
            }

            return $this->append($locked, PettyCashTxnType::ReturnOut, $amount, $data['txn_date'], 'return:'.Str::uuid(), $data['remarks'] ?? null, $user->id);
        });
    }

    /**
     * Pays an approved expense from its float (inside the approval transaction). Idempotent per
     * expense revision; refuses to overdraw the float.
     */
    public function drainForExpense(Expense $expense, ?int $userId): PettyCashTransaction
    {
        $locked = PettyCashAccount::query()->whereKey($expense->petty_cash_account_id)->lockForUpdate()->firstOrFail();
        $ref = "expense:{$expense->id}:r{$expense->revision}:out";
        if ($existing = PettyCashTransaction::query()->where('posting_ref', $ref)->first()) {
            return $existing;
        }

        $balance = $this->balanceOf($locked);
        if (Decimal::of($expense->total_amount)->greaterThan($balance)) {
            throw ValidationException::withMessages(['expense' => "The petty cash float \"{$locked->name}\" holds {$balance->toMoney()}, less than this expense ({$expense->total_amount}). Fund it first."]);
        }

        return $this->append($locked, PettyCashTxnType::ExpenseOut, Decimal::of($expense->total_amount), $expense->expense_date->toDateString(), $ref, $expense->expense_number, $userId, $expense->id);
    }

    /**
     * Compensating row for the expense's drain that is in force (no-op when none is).
     */
    public function reverseExpenseDrain(Expense $expense, string $remarks, ?int $userId): ?PettyCashTransaction
    {
        $active = PettyCashTransaction::query()
            ->where('expense_id', $expense->id)
            ->where('type', PettyCashTxnType::ExpenseOut)
            ->whereNull('reverses_id')
            ->whereNotExists(fn ($q) => $q->from('petty_cash_transactions as rev')->whereColumn('rev.reverses_id', 'petty_cash_transactions.id'))
            ->latest('id')
            ->first();
        if ($active === null) {
            return null;
        }

        PettyCashAccount::query()->whereKey($active->petty_cash_account_id)->lockForUpdate()->firstOrFail();
        $row = new PettyCashTransaction;
        $row->forceFill([
            'company_id' => $active->company_id,
            'petty_cash_account_id' => $active->petty_cash_account_id,
            'txn_date' => now()->toDateString(),
            'type' => PettyCashTxnType::ExpenseOut,
            'amount' => Decimal::of($active->amount)->negate()->toMoney(),
            'expense_id' => $expense->id,
            'reverses_id' => $active->id,
            'posting_ref' => "reversal:{$active->id}",
            'remarks' => $remarks,
            'created_by' => $userId,
        ])->save();

        return $row;
    }

    public function balance(PettyCashAccount $account): Decimal
    {
        return $this->balanceOf($account);
    }

    private function balanceOf(PettyCashAccount $account): Decimal
    {
        return $account->balance();
    }

    private function holder(Project $project, mixed $userId): User
    {
        $user = is_numeric($userId) ? User::query()->whereKey((int) $userId)->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $project->company_id)->where('is_active', true))->first() : null;
        if ($user === null || ! ($user->isProjectMember($project) || CompanyPermission::check($user, (int) $project->company_id, 'projects.view_all'))) {
            throw ValidationException::withMessages(['holder_user_id' => 'The holder must be an active member of this project.']);
        }

        return $user;
    }

    private function positive(mixed $value): Decimal
    {
        $amount = $this->amount($value, 'amount', required: true);
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        return $amount;
    }

    private function append(PettyCashAccount $account, PettyCashTxnType $type, Decimal $amount, string $date, string $ref, ?string $remarks, ?int $userId, ?int $expenseId = null): PettyCashTransaction
    {
        $row = new PettyCashTransaction;
        $row->forceFill([
            'company_id' => $account->company_id,
            'petty_cash_account_id' => $account->id,
            'txn_date' => $date,
            'type' => $type,
            'amount' => $amount->toMoney(),
            'expense_id' => $expenseId,
            'posting_ref' => $ref,
            'remarks' => $remarks,
            'created_by' => $userId,
        ])->save();

        return $row;
    }
}

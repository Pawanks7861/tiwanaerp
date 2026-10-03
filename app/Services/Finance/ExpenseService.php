<?php

namespace App\Services\Finance;

use App\Enums\CostHead;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PaymentMode;
use App\Models\Finance\Expense;
use App\Models\Finance\PettyCashAccount;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\Vendor;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expenses: draft → submitted (engine: PM → Accountant) → approved → paid.
 *
 * Final approval (approver holds expenses.approve) posts amount (excluding GST) to the project
 * cost ledger under the category's cost head, once per revision. A petty cash expense is drained
 * from its float in the same transaction and is paid at that moment; any other expense is marked
 * paid later, which records the cash-out only. Reversal (approved, or paid from petty cash)
 * appends compensating cost / petty cash rows and returns the expense to draft (revision + 1).
 */
class ExpenseService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProjectCostLedgerService $costs,
        private readonly PettyCashService $pettyCash,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data, User $user): Expense
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $expense = new Expense;
            $expense->forceFill([
                'project_id' => $project->id,
                'expense_number' => $this->numbers->next('expense', $project),
                'status' => ExpenseStatus::Draft,
                ...$this->attributes($project, $data, $user),
            ])->save();

            return $expense;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $data, $user) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->forceFill($this->attributes($locked->project, $data, $user))->save();

            return $locked;
        });
    }

    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if ($locked->revision > 0) {
                throw ValidationException::withMessages(['expense' => 'An expense that was approved before cannot be deleted; keep it as a draft or resubmit it.']);
            }
            $locked->delete();
        });
    }

    public function submit(Expense $expense, User $user): void
    {
        DB::transaction(function () use ($expense, $user) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if ($locked->isPettyCash()) {
                $account = PettyCashAccount::query()->findOrFail($locked->petty_cash_account_id);
                $balance = $this->pettyCash->balance($account);
                if (Decimal::of($locked->total_amount)->greaterThan($balance)) {
                    throw ValidationException::withMessages(['expense' => "The petty cash float \"{$account->name}\" holds {$balance->toMoney()}, less than this expense. Fund it first."]);
                }
            }

            $this->approvals->submit($locked, $user);
            $expense->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval, inside the engine's transaction. Idempotent.
     */
    public function approve(Expense $expense, ?int $approverId): void
    {
        DB::transaction(function () use ($expense, $approverId) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ExpenseStatus::approvedStates(), true)) {
                return;
            }
            if ($locked->status !== ExpenseStatus::Submitted) {
                throw ValidationException::withMessages(['expense' => 'Only a submitted expense can be approved.']);
            }
            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, 'expenses.approve')) {
                throw ValidationException::withMessages(['approval' => 'Approving an expense needs the expenses.approve permission.']);
            }

            $this->costs->post(
                source: $locked,
                projectId: $locked->project_id,
                head: $locked->cost_head,
                amount: Decimal::of($locked->amount),
                date: $locked->expense_date->toDateString(),
                boqItemId: $locked->boq_item_id,
                boqLineUid: $locked->boq_line_uid,
                taskId: $locked->task_id,
                remarks: $locked->expense_number,
                userId: $approverId,
            );

            $stamp = ['approved_by' => $approverId, 'approved_at' => now()];
            if ($locked->isPettyCash()) {
                $this->pettyCash->drainForExpense($locked, $approverId);
                $stamp += [
                    'status' => ExpenseStatus::Paid,
                    'paid_by' => $approverId,
                    'paid_at' => now(),
                    'paid_on' => $locked->expense_date->toDateString(),
                    'payment_reference' => 'Petty cash',
                ];
            } else {
                $stamp['status'] = ExpenseStatus::Approved;
            }

            $locked->forceFill($stamp)->save();
            $expense->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Records the cash-out of an approved (non petty cash) expense. Posts no cost.
     *
     * @param  array<string, mixed>  $data  paid_on, payment_reference
     */
    public function markPaid(Expense $expense, User $user, array $data): void
    {
        DB::transaction(function () use ($expense, $user, $data) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExpenseStatus::Approved) {
                throw ValidationException::withMessages(['expense' => 'Only an approved, unpaid expense can be marked paid.']);
            }
            if ($data['paid_on'] < $locked->expense_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be before the expense date.']);
            }

            $locked->forceFill([
                'status' => ExpenseStatus::Paid,
                'paid_by' => $user->id,
                'paid_at' => now(),
                'paid_on' => $data['paid_on'],
                'payment_reference' => $data['payment_reference'] ?? null,
            ])->save();
            $expense->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Correction of an approved expense (or a petty cash expense, which is paid at approval):
     * compensating cost and petty cash rows, back to draft as a new revision.
     */
    public function reverse(Expense $expense, User $user, string $reason): void
    {
        DB::transaction(function () use ($expense, $user, $reason) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $reversible = $locked->status === ExpenseStatus::Approved
                || ($locked->status === ExpenseStatus::Paid && $locked->isPettyCash());
            if (! $reversible) {
                throw ValidationException::withMessages(['expense' => 'Only an approved expense (or one paid from petty cash) can be reversed.']);
            }

            foreach (CostHead::cases() as $head) {
                $this->costs->reverseActive($locked, $head, "Reversed {$locked->expense_number}: {$reason}", $user->id);
            }
            if ($locked->isPettyCash()) {
                $this->pettyCash->reverseExpenseDrain($locked, "Reversed {$locked->expense_number}: {$reason}", $user->id);
            }

            $locked->forceFill([
                'status' => ExpenseStatus::Draft,
                'revision' => $locked->revision + 1,
                'approved_by' => null,
                'approved_at' => null,
                'paid_by' => null,
                'paid_at' => null,
                'paid_on' => null,
                'payment_reference' => null,
                'reversed_by' => $user->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();
            $expense->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Project $project, array $data, User $user): array
    {
        $category = ExpenseCategory::query()->whereKey((int) ($data['expense_category_id'] ?? 0))->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['expense_category_id' => 'Choose an active expense category.']);

        $vendorId = null;
        if (filled($data['vendor_id'] ?? null)) {
            $vendorId = Vendor::query()->whereKey((int) $data['vendor_id'])->where('is_active', true)->value('id')
                ?? throw ValidationException::withMessages(['vendor_id' => 'Choose an active vendor.']);
        }

        $amount = $this->amount($data['amount'] ?? null, 'amount', required: true);
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }
        $tax = $this->amount($data['tax_amount'] ?? null, 'tax_amount');

        $mode = PaymentMode::tryFrom((string) ($data['payment_mode'] ?? ''))
            ?? throw ValidationException::withMessages(['payment_mode' => 'Choose how the expense is paid.']);
        $accountId = null;
        if ($mode === PaymentMode::PettyCash) {
            $account = PettyCashAccount::query()->where('project_id', $project->id)->where('is_active', true)
                ->whereKey((int) ($data['petty_cash_account_id'] ?? 0))->first()
                ?? throw ValidationException::withMessages(['petty_cash_account_id' => 'Choose an active petty cash account of this project.']);
            $canSpend = CompanyPermission::check($user, (int) $project->company_id, 'petty_cash.spend')
                && ((int) $account->holder_user_id === (int) $user->id || CompanyPermission::check($user, (int) $project->company_id, 'petty_cash.fund'));
            if (! $canSpend) {
                throw ValidationException::withMessages(['petty_cash_account_id' => 'Only the holder of this float (with petty_cash.spend) or a petty cash manager can book expenses against it.']);
            }
            $accountId = $account->id;
        }

        $taskId = null;
        if (filled($data['task_id'] ?? null)) {
            $taskId = ProjectTask::query()->where('project_id', $project->id)->whereKey((int) $data['task_id'])->value('id')
                ?? throw ValidationException::withMessages(['task_id' => 'Choose a task of this project.']);
        }
        [$boqItemId, $boqLineUid] = $this->boqRefOfTask($taskId);
        if (filled($data['boq_item_id'] ?? null)) {
            $boq = $this->boqItem($project, $data['boq_item_id'], 'boq_item_id');
            [$boqItemId, $boqLineUid] = [$boq->id, $boq->line_uid];
        }

        if (blank($vendorId) && blank($data['payee_name'] ?? null)) {
            throw ValidationException::withMessages(['payee_name' => 'Enter the payee, or choose a vendor.']);
        }

        return [
            'expense_category_id' => $category->id,
            'cost_head' => $category->cost_head,
            'vendor_id' => $vendorId,
            'payee_name' => filled($data['payee_name'] ?? null) ? trim((string) $data['payee_name']) : null,
            'expense_date' => $data['expense_date'],
            'amount' => $amount->toMoney(),
            'tax_amount' => $tax->toMoney(),
            'total_amount' => $amount->plus($tax)->toMoney(),
            'payment_mode' => $mode,
            'petty_cash_account_id' => $accountId,
            'reference_no' => $data['reference_no'] ?? null,
            'description' => trim((string) $data['description']),
            'task_id' => $taskId,
            'boq_item_id' => $boqItemId,
            'boq_line_uid' => $boqLineUid,
        ];
    }
}

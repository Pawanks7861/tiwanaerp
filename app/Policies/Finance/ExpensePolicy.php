<?php

namespace App\Policies\Finance;

use App\Enums\Finance\ExpenseStatus;
use App\Models\Finance\Expense;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * expenses.* permission AND project access. Approval runs through the engine (expense: PM →
 * Accountant); the final approver additionally needs expenses.approve. Marking a non petty cash
 * expense paid is a cash action (payments.record).
 */
class ExpensePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('expenses.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can('expenses.view') && $this->projects->view($user, $expense->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('expenses.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can('expenses.update') && $expense->isEditable() && $this->projects->view($user, $expense->project);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can('expenses.delete') && $expense->isEditable() && $expense->revision === 0
            && $this->projects->view($user, $expense->project);
    }

    public function submit(User $user, Expense $expense): bool
    {
        return $user->can('expenses.submit') && $expense->isEditable() && $this->projects->view($user, $expense->project);
    }

    public function markPaid(User $user, Expense $expense): bool
    {
        return $user->can('payments.record') && $expense->status === ExpenseStatus::Approved
            && $this->projects->view($user, $expense->project);
    }

    public function reverse(User $user, Expense $expense): bool
    {
        $reversible = $expense->status === ExpenseStatus::Approved
            || ($expense->status === ExpenseStatus::Paid && $expense->isPettyCash());

        return $user->can('expenses.approve') && $reversible && $this->projects->view($user, $expense->project);
    }
}

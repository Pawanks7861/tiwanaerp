<?php

namespace App\Policies\Finance;

use App\Models\Finance\PettyCashAccount;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * petty_cash.view to see floats, petty_cash.fund to open, top up, edit and take cash back.
 * Booking an expense against a float is checked by the expense service (petty_cash.spend and
 * holder, or petty_cash.fund).
 */
class PettyCashAccountPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('petty_cash.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, PettyCashAccount $account): bool
    {
        return $user->can('petty_cash.view') && $this->projects->view($user, $account->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('petty_cash.fund') && $this->projects->view($user, $project);
    }

    public function update(User $user, PettyCashAccount $account): bool
    {
        return $user->can('petty_cash.fund') && $this->projects->view($user, $account->project);
    }

    public function fund(User $user, PettyCashAccount $account): bool
    {
        return $account->is_active && $this->update($user, $account);
    }
}

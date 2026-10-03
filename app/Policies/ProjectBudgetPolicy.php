<?php

namespace App\Policies;

use App\Enums\Boq\BudgetStatus;
use App\Models\Boq\ProjectBudget;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * Budgets are cost information: every ability also requires boq.view_costs.
 */
class ProjectBudgetPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'budget.view', $project);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->allowed($user, 'budget.update', $project);
    }

    public function approve(User $user, ProjectBudget $budget): bool
    {
        return $budget->status === BudgetStatus::Draft && $this->allowed($user, 'budget.approve', $budget->project);
    }

    private function allowed(User $user, string $permission, Project $project): bool
    {
        return $user->can($permission) && $user->can('boq.view_costs') && $this->projects->view($user, $project);
    }
}

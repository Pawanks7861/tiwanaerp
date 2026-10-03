<?php

namespace App\Policies\SiteExecution;

use App\Enums\SiteExecution\DprStatus;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * dpr.* permission AND project access. Approval itself runs through the approval engine; the
 * final approver additionally needs dpr.approve (checked when progress is posted).
 */
class DprPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('dpr.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Dpr $dpr): bool
    {
        return $user->can('dpr.view') && $this->projects->view($user, $dpr->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('dpr.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, Dpr $dpr): bool
    {
        return $user->can('dpr.update') && $dpr->isEditable() && $this->projects->view($user, $dpr->project);
    }

    public function delete(User $user, Dpr $dpr): bool
    {
        return $this->update($user, $dpr) && $dpr->revision === 0;
    }

    public function submit(User $user, Dpr $dpr): bool
    {
        return $user->can('dpr.submit') && $dpr->isEditable() && $this->projects->view($user, $dpr->project);
    }

    /** Correction of an approved DPR: its progress is reversed and it returns to draft. */
    public function reopen(User $user, Dpr $dpr): bool
    {
        return $user->can('dpr.approve') && $dpr->status === DprStatus::Approved && $this->projects->view($user, $dpr->project);
    }

    public function export(User $user, Dpr $dpr): bool
    {
        return $user->can('dpr.export') && $this->projects->view($user, $dpr->project);
    }
}

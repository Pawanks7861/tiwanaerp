<?php

namespace App\Policies\Quality;

use App\Enums\Quality\NcrStatus;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * quality.* permission AND project access. Raising, assigning and resolving is quality.raise_ncr;
 * verifying (or sending a resolution back) is quality.perform_inspection by someone other than the
 * resolver; closing is quality.close_ncr.
 */
class NcrPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('quality.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Ncr $ncr): bool
    {
        return $user->can('quality.view') && $this->projects->view($user, $ncr->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('quality.raise_ncr') && $this->projects->view($user, $project);
    }

    public function update(User $user, Ncr $ncr): bool
    {
        return $user->can('quality.raise_ncr') && $ncr->isEditable() && $this->projects->view($user, $ncr->project);
    }

    public function delete(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.raise_ncr', NcrStatus::Open);
    }

    public function start(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.raise_ncr', NcrStatus::Open);
    }

    public function resolve(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.raise_ncr', NcrStatus::InProgress);
    }

    public function verify(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.perform_inspection', NcrStatus::Resolved)
            && (int) $ncr->resolved_by !== (int) $user->id;
    }

    public function reopen(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.perform_inspection', NcrStatus::Resolved);
    }

    public function close(User $user, Ncr $ncr): bool
    {
        return $this->in($user, $ncr, 'quality.close_ncr', NcrStatus::Verified);
    }

    private function in(User $user, Ncr $ncr, string $permission, NcrStatus $status): bool
    {
        return $user->can($permission) && $ncr->status === $status && $this->projects->view($user, $ncr->project);
    }
}

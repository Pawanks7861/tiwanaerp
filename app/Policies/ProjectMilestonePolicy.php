<?php

namespace App\Policies;

use App\Models\Planning\ProjectMilestone;
use App\Models\Projects\Project;
use App\Models\User;

class ProjectMilestonePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('planning.view') && $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('planning.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, ProjectMilestone $milestone): bool
    {
        return $user->can('planning.update') && $this->projects->view($user, $milestone->project);
    }

    public function delete(User $user, ProjectMilestone $milestone): bool
    {
        return $user->can('planning.delete') && $this->projects->view($user, $milestone->project);
    }
}

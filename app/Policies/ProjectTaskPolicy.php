<?php

namespace App\Policies;

use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * Planning (tasks, dependencies, milestones): planning.* permission AND project access.
 */
class ProjectTaskPolicy
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

    public function update(User $user, ProjectTask $task): bool
    {
        return $user->can('planning.update') && $this->projects->view($user, $task->project);
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $user->can('planning.delete') && $this->projects->view($user, $task->project);
    }

    public function updateProgress(User $user, ProjectTask $task): bool
    {
        return $user->can('planning.update_progress') && $this->projects->view($user, $task->project);
    }
}

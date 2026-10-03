<?php

namespace App\Policies;

use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        if ((int) $project->company_id !== app(CurrentCompany::class)->id() || ! $user->can('projects.view')) {
            return false;
        }

        return $user->can('projects.view_all') || $user->isProjectMember($project);
    }

    public function create(User $user): bool
    {
        return $user->can('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->can('projects.update');
    }

    public function changeStatus(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function manageTeam(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->can('projects.manage_team');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->can('projects.delete');
    }
}

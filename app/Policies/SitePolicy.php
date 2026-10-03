<?php

namespace App\Policies;

use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;

/**
 * Sites are managed inside a project: the user needs the sites.* permission AND project access.
 */
class SitePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('sites.view') && $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('sites.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can('sites.update') && $this->projects->view($user, $site->project);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->can('sites.delete') && $this->projects->view($user, $site->project);
    }
}

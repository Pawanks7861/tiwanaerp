<?php

namespace App\Policies\Procurement;

use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Procurement documents live inside a project: every ability needs the permission AND project access.
 * State rules are repeated in the services because super admins bypass policies.
 */
class MaterialRequestPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('material_requests.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, MaterialRequest $mr): bool
    {
        return $user->can('material_requests.view') && $this->projects->view($user, $mr->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('material_requests.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, MaterialRequest $mr): bool
    {
        return $user->can('material_requests.update') && $mr->isEditable() && $this->projects->view($user, $mr->project);
    }

    public function delete(User $user, MaterialRequest $mr): bool
    {
        return $user->can('material_requests.delete') && $mr->isEditable() && $this->projects->view($user, $mr->project);
    }

    public function submit(User $user, MaterialRequest $mr): bool
    {
        return $user->can('material_requests.submit') && $mr->isEditable() && $this->projects->view($user, $mr->project);
    }

    public function cancel(User $user, MaterialRequest $mr): bool
    {
        return $user->can('material_requests.approve') && $mr->status->isProcurable() && $this->projects->view($user, $mr->project);
    }
}

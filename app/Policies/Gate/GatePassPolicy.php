<?php

namespace App\Policies\Gate;

use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class GatePassPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('gate_pass.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.view') && $this->projects->view($user, $pass->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('gate_pass.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.create') && $pass->isEditable() && $this->projects->view($user, $pass->project);
    }

    public function delete(User $user, GatePass $pass): bool
    {
        return $this->update($user, $pass);
    }

    public function approve(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.approve') && $this->projects->view($user, $pass->project);
    }

    public function verify(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.verify') && $this->projects->view($user, $pass->project);
    }
}

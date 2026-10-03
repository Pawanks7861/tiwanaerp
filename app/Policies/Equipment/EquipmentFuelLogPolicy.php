<?php

namespace App\Policies\Equipment;

use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class EquipmentFuelLogPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('equipment.view') && $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('equipment.log_usage') && $this->projects->view($user, $project);
    }

    public function update(User $user, EquipmentFuelLog $log): bool
    {
        return $user->can('equipment.log_usage') && $this->projects->view($user, $log->project);
    }

    public function delete(User $user, EquipmentFuelLog $log): bool
    {
        return $this->update($user, $log);
    }
}

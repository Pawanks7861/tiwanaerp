<?php

namespace App\Policies\Equipment;

use App\Models\Equipment\EquipmentAssignment;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class EquipmentAssignmentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('equipment.view') && $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('equipment.assign') && $this->projects->view($user, $project);
    }

    public function update(User $user, EquipmentAssignment $assignment): bool
    {
        return $user->can('equipment.assign') && $assignment->isActive() && $this->projects->view($user, $assignment->project);
    }

    public function returnEquipment(User $user, EquipmentAssignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }
}

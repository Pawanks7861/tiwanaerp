<?php

namespace App\Policies\Equipment;

use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Site staff log usage (equipment.log_usage); posting the cost, and reversing it, is for whoever
 * controls deployment (equipment.assign).
 */
class EquipmentUsageLogPolicy
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

    public function update(User $user, EquipmentUsageLog $log): bool
    {
        return $user->can('equipment.log_usage') && ! $log->isPosted() && $this->projects->view($user, $log->project);
    }

    public function delete(User $user, EquipmentUsageLog $log): bool
    {
        return $this->update($user, $log);
    }

    public function post(User $user, Project $project): bool
    {
        return $user->can('equipment.assign') && $this->projects->view($user, $project);
    }

    public function reverse(User $user, EquipmentUsageLog $log): bool
    {
        return $user->can('equipment.assign') && $log->isPosted() && $this->projects->view($user, $log->project);
    }
}

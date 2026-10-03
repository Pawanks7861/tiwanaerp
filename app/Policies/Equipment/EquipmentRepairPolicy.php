<?php

namespace App\Policies\Equipment;

use App\Models\Equipment\EquipmentRepair;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\Tenancy\CurrentCompany;

class EquipmentRepairPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('equipment.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, EquipmentRepair $repair): bool
    {
        return $user->can('equipment.view') && $this->inScope($user, $repair);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('equipment.update') && $this->projects->view($user, $project);
    }

    /** Open repairs only; also gates attachments (bills, job cards). */
    public function update(User $user, EquipmentRepair $repair): bool
    {
        return $user->can('equipment.update') && $repair->isOpen() && $this->inScope($user, $repair);
    }

    public function complete(User $user, EquipmentRepair $repair): bool
    {
        return $this->update($user, $repair);
    }

    public function cancel(User $user, EquipmentRepair $repair): bool
    {
        return $this->update($user, $repair);
    }

    private function inScope(User $user, EquipmentRepair $repair): bool
    {
        if ((int) $repair->company_id !== app(CurrentCompany::class)->id()) {
            return false;
        }

        return $repair->project_id === null || $this->projects->view($user, $repair->project);
    }
}

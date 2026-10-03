<?php

namespace App\Policies\Inventory;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Models\Inventory\MaterialReturn;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class MaterialReturnPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, MaterialReturn $return): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $return->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('inventory.return') && $this->projects->view($user, $project);
    }

    public function update(User $user, MaterialReturn $return): bool
    {
        return $user->can('inventory.return') && $return->isEditable() && $this->projects->view($user, $return->project);
    }

    public function delete(User $user, MaterialReturn $return): bool
    {
        return $this->update($user, $return);
    }

    public function submit(User $user, MaterialReturn $return): bool
    {
        return $this->update($user, $return);
    }

    /** Reversing a posted return is an adjustment-level privilege. */
    public function cancel(User $user, MaterialReturn $return): bool
    {
        return $user->can('inventory.approve_adjustment') && $return->status === InventoryDocumentStatus::Approved
            && $this->projects->view($user, $return->project);
    }
}

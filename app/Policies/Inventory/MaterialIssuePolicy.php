<?php

namespace App\Policies\Inventory;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Models\Inventory\MaterialIssue;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class MaterialIssuePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, MaterialIssue $issue): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $issue->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('inventory.issue') && $this->projects->view($user, $project);
    }

    public function update(User $user, MaterialIssue $issue): bool
    {
        return $user->can('inventory.issue') && $issue->isEditable() && $this->projects->view($user, $issue->project);
    }

    public function delete(User $user, MaterialIssue $issue): bool
    {
        return $this->update($user, $issue);
    }

    public function submit(User $user, MaterialIssue $issue): bool
    {
        return $this->update($user, $issue);
    }

    /** Reversing a posted issue is an adjustment-level privilege. */
    public function cancel(User $user, MaterialIssue $issue): bool
    {
        return $user->can('inventory.approve_adjustment') && $issue->status === InventoryDocumentStatus::Approved
            && $this->projects->view($user, $issue->project);
    }
}

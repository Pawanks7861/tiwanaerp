<?php

namespace App\Policies\Procurement;

use App\Models\Procurement\Grn;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class GrnPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('grn.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Grn $grn): bool
    {
        return $user->can('grn.view') && $this->projects->view($user, $grn->project);
    }

    public function create(User $user, PurchaseOrder $po): bool
    {
        return $user->can('grn.create') && $po->status->isReceivable() && $this->projects->view($user, $po->project);
    }

    public function update(User $user, Grn $grn): bool
    {
        return $user->can('grn.update') && $grn->isEditable() && $this->projects->view($user, $grn->project);
    }

    public function delete(User $user, Grn $grn): bool
    {
        return $user->can('grn.delete') && $grn->isEditable() && $this->projects->view($user, $grn->project);
    }

    public function submit(User $user, Grn $grn): bool
    {
        return $user->can('grn.submit') && $grn->isEditable() && $this->projects->view($user, $grn->project);
    }
}

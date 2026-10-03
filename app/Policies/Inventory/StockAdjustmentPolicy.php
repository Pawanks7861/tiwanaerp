<?php

namespace App\Policies\Inventory;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Models\Inventory\StockAdjustment;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class StockAdjustmentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $adjustment->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('inventory.adjust') && $this->projects->view($user, $project);
    }

    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $user->can('inventory.adjust') && $adjustment->isEditable() && $this->projects->view($user, $adjustment->project);
    }

    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return $this->update($user, $adjustment);
    }

    public function submit(User $user, StockAdjustment $adjustment): bool
    {
        return $this->update($user, $adjustment);
    }

    /** Segregation of duties: the submitter cannot approve or reject their own adjustment. */
    public function approve(User $user, StockAdjustment $adjustment): bool
    {
        return $user->can('inventory.approve_adjustment')
            && $adjustment->status === InventoryDocumentStatus::Submitted
            && (int) $adjustment->submitted_by !== $user->id
            && $this->projects->view($user, $adjustment->project);
    }

    public function reject(User $user, StockAdjustment $adjustment): bool
    {
        return $this->approve($user, $adjustment);
    }

    public function cancel(User $user, StockAdjustment $adjustment): bool
    {
        return $user->can('inventory.approve_adjustment') && $adjustment->status === InventoryDocumentStatus::Approved
            && $this->projects->view($user, $adjustment->project);
    }
}

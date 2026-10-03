<?php

namespace App\Policies\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class PurchaseOrderPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('purchase.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.view') && $this->projects->view($user, $po->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('purchase.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.update') && $po->isEditable() && $this->projects->view($user, $po->project);
    }

    public function delete(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.delete') && $po->isEditable() && $po->revision_no === 0 && $this->projects->view($user, $po->project);
    }

    public function submit(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.submit') && $po->isEditable() && $this->projects->view($user, $po->project);
    }

    public function amend(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.amend')
            && in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)
            && $this->projects->view($user, $po->project);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        $cancellable = in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Rejected], true)
            || ($po->status === PurchaseOrderStatus::Draft && $po->revision_no > 0);

        return $user->can('purchase.cancel') && $cancellable && $this->projects->view($user, $po->project);
    }

    public function close(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.cancel')
            && in_array($po->status, [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], true)
            && $this->projects->view($user, $po->project);
    }

    public function export(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchase.export') && $this->projects->view($user, $po->project);
    }
}

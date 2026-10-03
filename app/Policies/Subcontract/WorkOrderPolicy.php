<?php

namespace App\Policies\Subcontract;

use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Projects\Project;
use App\Models\Subcontract\WorkOrder;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * subcontract.* permission AND project access. Approval runs through the engine (work_order:
 * PM → Director); the final approver additionally needs subcontract.approve_wo.
 */
class WorkOrderPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('subcontract.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, WorkOrder $order): bool
    {
        return $user->can('subcontract.view') && $this->projects->view($user, $order->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('subcontract.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, WorkOrder $order): bool
    {
        return $user->can('subcontract.update') && $order->isEditable() && $this->projects->view($user, $order->project);
    }

    public function delete(User $user, WorkOrder $order): bool
    {
        return $user->can('subcontract.delete') && $order->status === WorkOrderStatus::Draft
            && $this->projects->view($user, $order->project);
    }

    public function submit(User $user, WorkOrder $order): bool
    {
        return $this->update($user, $order);
    }

    /** Recording milestone achievement on an approved order. */
    public function updateMilestones(User $user, WorkOrder $order): bool
    {
        return $user->can('subcontract.update') && $order->status->isBillable() && $this->projects->view($user, $order->project);
    }

    public function complete(User $user, WorkOrder $order): bool
    {
        return $this->manages($user, $order) && in_array($order->status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true);
    }

    public function close(User $user, WorkOrder $order): bool
    {
        return $this->manages($user, $order) && $order->status->isBillable();
    }

    public function cancel(User $user, WorkOrder $order): bool
    {
        return $this->manages($user, $order) && in_array($order->status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true);
    }

    private function manages(User $user, WorkOrder $order): bool
    {
        return $user->can('subcontract.approve_wo') && $this->projects->view($user, $order->project);
    }
}

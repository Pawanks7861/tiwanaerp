<?php

namespace App\Policies\Finance;

use App\Models\Finance\VendorBill;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * vendor_bills.* permission AND project access. Approval runs through the engine (vendor_bill:
 * PM → Director); the final approver additionally needs vendor_bills.approve.
 */
class VendorBillPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('vendor_bills.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, VendorBill $bill): bool
    {
        return $user->can('vendor_bills.view') && $this->projects->view($user, $bill->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('vendor_bills.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, VendorBill $bill): bool
    {
        return $user->can('vendor_bills.update') && $bill->isEditable() && $this->projects->view($user, $bill->project);
    }

    public function delete(User $user, VendorBill $bill): bool
    {
        return $user->can('vendor_bills.delete') && $bill->isEditable() && $this->projects->view($user, $bill->project);
    }

    public function submit(User $user, VendorBill $bill): bool
    {
        return $this->update($user, $bill);
    }
}

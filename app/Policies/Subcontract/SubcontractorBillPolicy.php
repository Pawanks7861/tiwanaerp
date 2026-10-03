<?php

namespace App\Policies\Subcontract;

use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Approval\ApprovalService;

/**
 * subcontract.* permission AND project access. Certification runs through the engine
 * (subcontractor_bill: PM → Director); the final approver additionally needs
 * subcontract.certify_bill.
 */
class SubcontractorBillPolicy
{
    public function __construct(private readonly ProjectPolicy $projects, private readonly ApprovalService $approvals) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('subcontract.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, SubcontractorBill $bill): bool
    {
        return $user->can('subcontract.view') && $this->projects->view($user, $bill->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('subcontract.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, SubcontractorBill $bill): bool
    {
        return $user->can('subcontract.update') && $bill->isEditable() && $this->projects->view($user, $bill->project);
    }

    public function delete(User $user, SubcontractorBill $bill): bool
    {
        return $user->can('subcontract.delete') && $bill->isEditable() && $bill->revision === 0
            && $this->projects->view($user, $bill->project);
    }

    public function submit(User $user, SubcontractorBill $bill): bool
    {
        return $this->update($user, $bill);
    }

    /** Adjusting certified quantities / deductions while the bill awaits this user's approval. */
    public function certify(User $user, SubcontractorBill $bill): bool
    {
        if (! $user->can('subcontract.certify_bill') || $bill->status !== SubcontractorBillStatus::Submitted
            || ! $this->projects->view($user, $bill->project)) {
            return false;
        }
        $request = $bill->pendingApprovalRequest();

        return $request !== null && $this->approvals->canAct($request, $user);
    }

    /** Correction of a certified bill: its cost is reversed and it returns to draft. */
    public function reverse(User $user, SubcontractorBill $bill): bool
    {
        return $user->can('subcontract.certify_bill') && $bill->status === SubcontractorBillStatus::Certified
            && $this->projects->view($user, $bill->project);
    }
}

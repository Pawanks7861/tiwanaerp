<?php

namespace App\Policies\Labour;

use App\Enums\Labour\LabourPaymentStatus;
use App\Models\Labour\LabourPayment;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * labour.manage_payments AND project access. Approval is a direct action (no engine workflow is
 * defined for labour payments in J.1) with maker-checker: the submitter cannot approve.
 */
class LabourPaymentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('labour.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, LabourPayment $payment): bool
    {
        return $user->can('labour.view') && $this->projects->view($user, $payment->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('labour.manage_payments') && $this->projects->view($user, $project);
    }

    public function update(User $user, LabourPayment $payment): bool
    {
        return $this->manages($user, $payment) && $payment->isEditable();
    }

    public function delete(User $user, LabourPayment $payment): bool
    {
        return $this->update($user, $payment);
    }

    public function submit(User $user, LabourPayment $payment): bool
    {
        return $this->update($user, $payment);
    }

    public function approve(User $user, LabourPayment $payment): bool
    {
        return $this->manages($user, $payment) && $payment->status === LabourPaymentStatus::Submitted
            && (int) $payment->submitted_by !== (int) $user->id;
    }

    public function sendBack(User $user, LabourPayment $payment): bool
    {
        return $this->manages($user, $payment) && $payment->status === LabourPaymentStatus::Submitted;
    }

    public function markPaid(User $user, LabourPayment $payment): bool
    {
        return $this->manages($user, $payment) && $payment->status === LabourPaymentStatus::Approved;
    }

    private function manages(User $user, LabourPayment $payment): bool
    {
        return $user->can('labour.manage_payments') && $this->projects->view($user, $payment->project);
    }
}

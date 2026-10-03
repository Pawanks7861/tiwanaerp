<?php

namespace App\Policies\Finance;

use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\Payment;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Receipts and payments: payments.record drafts them, payments.approve (a different user — maker
 * ≠ checker) approves or cancels them. Always within a visible project.
 */
class PaymentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('payments.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view') && $this->projects->view($user, $payment->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('payments.record') && $this->projects->view($user, $project);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->can('payments.record') && $payment->status === PaymentStatus::Draft
            && $this->projects->view($user, $payment->project);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->update($user, $payment);
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $user->can('payments.approve') && $payment->status === PaymentStatus::Draft
            && (int) $payment->created_by !== (int) $user->id
            && $this->projects->view($user, $payment->project);
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $user->can('payments.approve') && $payment->status === PaymentStatus::Approved
            && $this->projects->view($user, $payment->project);
    }
}

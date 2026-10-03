<?php

namespace App\Policies\Procurement;

use App\Enums\Procurement\RfqStatus;
use App\Models\Procurement\Rfq;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class RfqPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('rfq.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.view') && $this->projects->view($user, $rfq->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('rfq.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.update') && $rfq->isEditable() && $this->projects->view($user, $rfq->project);
    }

    public function manageVendors(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.update')
            && in_array($rfq->status, [RfqStatus::Draft, RfqStatus::Sent, RfqStatus::QuotesReceived], true)
            && $this->projects->view($user, $rfq->project);
    }

    public function delete(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.delete') && $rfq->isEditable() && $this->projects->view($user, $rfq->project);
    }

    public function send(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.send') && $rfq->status === RfqStatus::Draft && $this->projects->view($user, $rfq->project);
    }

    public function close(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.update')
            && in_array($rfq->status, [RfqStatus::Sent, RfqStatus::QuotesReceived, RfqStatus::Evaluated], true)
            && $this->projects->view($user, $rfq->project);
    }

    public function cancel(User $user, Rfq $rfq): bool
    {
        return $user->can('rfq.delete') && $rfq->status->isOpen() && $rfq->status !== RfqStatus::Draft
            && $this->projects->view($user, $rfq->project);
    }
}

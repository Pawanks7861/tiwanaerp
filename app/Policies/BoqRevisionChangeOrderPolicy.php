<?php

namespace App\Policies;

use App\Enums\Boq\RevisionChangeOrderStatus;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Projects\Project;
use App\Models\User;

class BoqRevisionChangeOrderPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user): bool
    {
        return $user->can('boq.view');
    }

    public function view(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $user->can('boq.view') && $this->projects->view($user, $changeOrder->project);
    }

    public function create(User $user, Project $project): bool
    {
        return ($user->can('boq.revise') || $user->can('boq.update'))
            && $this->projects->view($user, $project);
    }

    public function submit(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $user->can('boq.submit')
            && $changeOrder->status->isEditable()
            && $this->projects->view($user, $changeOrder->project);
    }

    public function approve(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $user->can('boq.approve')
            && $changeOrder->status === RevisionChangeOrderStatus::UnderReview
            && $this->projects->view($user, $changeOrder->project);
    }

    public function reject(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $this->approve($user, $changeOrder);
    }

    public function cancel(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return ($user->can('boq.update') || $user->can('boq.revise'))
            && $changeOrder->status !== RevisionChangeOrderStatus::Approved
            && $changeOrder->status !== RevisionChangeOrderStatus::Cancelled
            && $this->projects->view($user, $changeOrder->project);
    }

    public function downloadInternalPdf(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $user->can('boq.view_costs') && $this->view($user, $changeOrder);
    }

    public function downloadClientPdf(User $user, BoqRevisionChangeOrder $changeOrder): bool
    {
        return $this->view($user, $changeOrder);
    }
}

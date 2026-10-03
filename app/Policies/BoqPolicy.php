<?php

namespace App\Policies;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\Boq;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * BOQs live inside a project: every ability needs the boq.* permission AND project access.
 * State rules (draft-only editing etc.) are repeated in BoqService because super admins bypass policies.
 */
class BoqPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('boq.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Boq $boq): bool
    {
        return $user->can('boq.view') && $this->projects->view($user, $boq->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('boq.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, Boq $boq): bool
    {
        return $user->can('boq.update') && $boq->isEditable() && $this->projects->view($user, $boq->project);
    }

    public function delete(User $user, Boq $boq): bool
    {
        return $user->can('boq.delete') && $boq->isEditable() && $this->projects->view($user, $boq->project);
    }

    public function submit(User $user, Boq $boq): bool
    {
        return $user->can('boq.submit') && $boq->isEditable() && $this->projects->view($user, $boq->project);
    }

    public function revise(User $user, Boq $boq): bool
    {
        return $user->can('boq.revise') && $boq->status === BoqStatus::Approved && $boq->is_current
            && $this->projects->view($user, $boq->project);
    }

    public function import(User $user, Boq $boq): bool
    {
        return $user->can('boq.import') && $user->can('boq.update') && $boq->isEditable()
            && $this->projects->view($user, $boq->project);
    }

    public function export(User $user, Boq $boq): bool
    {
        return $user->can('boq.export') && $this->projects->view($user, $boq->project);
    }
}

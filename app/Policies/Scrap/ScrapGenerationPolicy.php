<?php

namespace App\Policies\Scrap;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Models\User;
use App\Policies\ProjectPolicy;

class ScrapGenerationPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAnyCompany(User $user): bool
    {
        return $user->can('scrap.view');
    }

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('scrap.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, ScrapGeneration $generation): bool
    {
        return $user->can('scrap.view') && $this->projects->view($user, $generation->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('scrap.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, ScrapGeneration $generation): bool
    {
        return $user->can('scrap.update') && $generation->isEditable() && $this->projects->view($user, $generation->project);
    }

    public function delete(User $user, ScrapGeneration $generation): bool
    {
        return $this->update($user, $generation);
    }

    public function submit(User $user, ScrapGeneration $generation): bool
    {
        return $user->can('scrap.submit') && $generation->isEditable() && $this->projects->view($user, $generation->project);
    }

    public function approve(User $user, ScrapGeneration $generation): bool
    {
        return $user->can('scrap.approve')
            && $generation->status === ScrapGenerationStatus::Submitted
            && $this->projects->view($user, $generation->project);
    }

    public function cancel(User $user, ScrapGeneration $generation): bool
    {
        return $user->can('scrap.approve')
            && in_array($generation->status, [ScrapGenerationStatus::Submitted, ScrapGenerationStatus::Draft], true)
            && $this->projects->view($user, $generation->project);
    }
}

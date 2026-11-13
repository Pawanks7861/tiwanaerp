<?php

namespace App\Policies\Scrap;

use App\Enums\Scrap\ScrapAdjustmentStatus;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapAdjustment;
use App\Models\User;
use App\Policies\ProjectPolicy;

class ScrapAdjustmentPolicy
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

    public function view(User $user, ScrapAdjustment $adjustment): bool
    {
        return $user->can('scrap.view') && $this->projects->view($user, $adjustment->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('scrap.adjust') && $this->projects->view($user, $project);
    }

    public function update(User $user, ScrapAdjustment $adjustment): bool
    {
        return $user->can('scrap.adjust') && $adjustment->isEditable() && $this->projects->view($user, $adjustment->project);
    }

    public function delete(User $user, ScrapAdjustment $adjustment): bool
    {
        return $this->update($user, $adjustment);
    }

    public function submit(User $user, ScrapAdjustment $adjustment): bool
    {
        return $this->update($user, $adjustment);
    }

    public function approve(User $user, ScrapAdjustment $adjustment): bool
    {
        return $user->can('scrap.approve')
            && $adjustment->status === ScrapAdjustmentStatus::Submitted
            && $this->projects->view($user, $adjustment->project);
    }
}

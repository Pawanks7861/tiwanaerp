<?php

namespace App\Policies\Quality;

use App\Enums\Quality\InspectionStatus;
use App\Models\Projects\Project;
use App\Models\Quality\QualityInspection;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * quality.* permission AND project access. Requesting / editing an inspection is
 * quality.create_inspection; scheduling, recording checkpoints and completing are
 * quality.perform_inspection. update (used for evidence attachments) is either, until completed.
 */
class QualityInspectionPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('quality.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, QualityInspection $inspection): bool
    {
        return $user->can('quality.view') && $this->projects->view($user, $inspection->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('quality.create_inspection') && $this->projects->view($user, $project);
    }

    public function update(User $user, QualityInspection $inspection): bool
    {
        return ($user->can('quality.create_inspection') || $user->can('quality.perform_inspection'))
            && $inspection->isEditable() && $this->projects->view($user, $inspection->project);
    }

    public function edit(User $user, QualityInspection $inspection): bool
    {
        return $user->can('quality.create_inspection') && $inspection->isEditable()
            && $this->projects->view($user, $inspection->project);
    }

    public function delete(User $user, QualityInspection $inspection): bool
    {
        return $user->can('quality.create_inspection') && $inspection->status === InspectionStatus::Requested
            && $this->projects->view($user, $inspection->project);
    }

    public function schedule(User $user, QualityInspection $inspection): bool
    {
        return $user->can('quality.perform_inspection') && $inspection->status === InspectionStatus::Requested
            && $this->projects->view($user, $inspection->project);
    }

    public function perform(User $user, QualityInspection $inspection): bool
    {
        return $user->can('quality.perform_inspection') && $inspection->status === InspectionStatus::Scheduled
            && $this->projects->view($user, $inspection->project);
    }
}

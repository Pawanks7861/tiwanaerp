<?php

namespace App\Policies\Documents;

use App\Models\Documents\Drawing;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * drawings.* permission AND project access. Revision state rules (one open revision at a time,
 * draft → submitted → under review → approved / rejected) are enforced by DrawingService.
 */
class DrawingPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('drawings.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Drawing $drawing): bool
    {
        return $user->can('drawings.view') && $this->projects->view($user, $drawing->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('drawings.upload') && $this->projects->view($user, $project);
    }

    /** Register details, new revisions, submitting and withdrawing drafts. */
    public function upload(User $user, Drawing $drawing): bool
    {
        return $user->can('drawings.upload') && $this->projects->view($user, $drawing->project);
    }

    public function review(User $user, Drawing $drawing): bool
    {
        return $user->can('drawings.review') && $this->projects->view($user, $drawing->project);
    }

    public function approve(User $user, Drawing $drawing): bool
    {
        return $user->can('drawings.approve') && $this->projects->view($user, $drawing->project);
    }

    public function reject(User $user, Drawing $drawing): bool
    {
        return ($user->can('drawings.review') || $user->can('drawings.approve')) && $this->projects->view($user, $drawing->project);
    }
}

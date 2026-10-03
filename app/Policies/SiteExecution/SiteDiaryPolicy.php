<?php

namespace App\Policies\SiteExecution;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * site_diary.* permission AND project access. Authors (or reviewers/approvers) edit their drafts;
 * nobody reviews, approves or rejects a diary they submitted themselves.
 */
class SiteDiaryPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('site_diary.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.view') && $this->projects->view($user, $diary->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('site_diary.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.update') && $this->editableBy($user, $diary);
    }

    public function delete(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.delete') && $this->editableBy($user, $diary);
    }

    public function submit(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.submit') && $this->editableBy($user, $diary);
    }

    public function review(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.review') && $diary->status === SiteDiaryStatus::Submitted
            && ! $this->isSubmitter($user, $diary) && $this->projects->view($user, $diary->project);
    }

    public function approve(User $user, SiteDiary $diary): bool
    {
        return $user->can('site_diary.approve') && $diary->status === SiteDiaryStatus::Reviewed
            && ! $this->isSubmitter($user, $diary) && $this->projects->view($user, $diary->project);
    }

    public function reject(User $user, SiteDiary $diary): bool
    {
        $allowed = match ($diary->status) {
            SiteDiaryStatus::Submitted => $user->can('site_diary.review') || $user->can('site_diary.approve'),
            SiteDiaryStatus::Reviewed => $user->can('site_diary.approve'),
            default => false,
        };

        return $allowed && ! $this->isSubmitter($user, $diary) && $this->projects->view($user, $diary->project);
    }

    private function editableBy(User $user, SiteDiary $diary): bool
    {
        $ownerOrSupervisor = (int) $diary->created_by === (int) $user->id
            || $user->can('site_diary.review') || $user->can('site_diary.approve');

        return $diary->isEditable() && $ownerOrSupervisor && $this->projects->view($user, $diary->project);
    }

    private function isSubmitter(User $user, SiteDiary $diary): bool
    {
        return (int) ($diary->submitted_by ?? $diary->created_by) === (int) $user->id;
    }
}

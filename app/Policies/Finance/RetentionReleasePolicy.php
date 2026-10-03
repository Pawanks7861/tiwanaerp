<?php

namespace App\Policies\Finance;

use App\Models\Finance\RetentionRelease;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Retention releases. Client retention follows billing.* (create / certify), subcontractor
 * retention follows subcontract.* (create / certify_bill); the engine (retention_release: PM →
 * Director) approves, and the final approver needs the matching certify permission.
 */
class RetentionReleasePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return ($user->can('billing.view') || $user->can('subcontract.view')) && $this->projects->view($user, $project);
    }

    public function view(User $user, RetentionRelease $release): bool
    {
        return $user->can($this->module($release).'.view') && $this->projects->view($user, $release->project);
    }

    public function create(User $user, Project $project): bool
    {
        return ($user->can('billing.create') || $user->can('subcontract.create')) && $this->projects->view($user, $project);
    }

    public function update(User $user, RetentionRelease $release): bool
    {
        return $user->can($this->module($release).'.create') && $release->isEditable()
            && $this->projects->view($user, $release->project);
    }

    public function delete(User $user, RetentionRelease $release): bool
    {
        return $this->update($user, $release);
    }

    public function submit(User $user, RetentionRelease $release): bool
    {
        return $this->update($user, $release);
    }

    private function module(RetentionRelease $release): string
    {
        return $release->releasable_type === 'client_invoice' ? 'billing' : 'subcontract';
    }
}

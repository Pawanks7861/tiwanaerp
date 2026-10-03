<?php

namespace App\Policies\Labour;

use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * labour.* permission AND project access. Marking and approving are separate permissions;
 * approved days are frozen, and only un-approved (with a reason) while not yet in a payment.
 */
class LabourAttendancePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('labour.view') && $this->projects->view($user, $project);
    }

    public function mark(User $user, Project $project): bool
    {
        return $user->can('labour.mark_attendance') && $this->projects->view($user, $project);
    }

    public function update(User $user, LabourAttendance $row): bool
    {
        return $user->can('labour.mark_attendance') && ! $row->isApproved() && $this->projects->view($user, $row->project);
    }

    public function delete(User $user, LabourAttendance $row): bool
    {
        return $this->update($user, $row);
    }

    public function approve(User $user, Project $project): bool
    {
        return $user->can('labour.approve_attendance') && $this->projects->view($user, $project);
    }

    public function unapprove(User $user, LabourAttendance $row): bool
    {
        return $user->can('labour.approve_attendance') && $row->isApproved() && $row->labour_payment_id === null
            && $this->projects->view($user, $row->project);
    }
}

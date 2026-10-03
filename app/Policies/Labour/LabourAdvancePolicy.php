<?php

namespace App\Policies\Labour;

use App\Models\Labour\LabourAdvance;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\Math\Decimal;

class LabourAdvancePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('labour.view') && $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('labour.manage_payments') && $this->projects->view($user, $project);
    }

    /** Only an advance nothing has been recovered from yet. */
    public function delete(User $user, LabourAdvance $advance): bool
    {
        return $user->can('labour.manage_payments') && Decimal::of($advance->recovered_amount)->isZero()
            && $this->projects->view($user, $advance->project);
    }
}

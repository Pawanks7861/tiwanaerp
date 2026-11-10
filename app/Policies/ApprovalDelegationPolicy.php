<?php

namespace App\Policies;

use App\Models\Approval\ApprovalDelegation;
use App\Models\User;

class ApprovalDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approvals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('approvals.view');
    }

    public function delete(User $user, ApprovalDelegation $delegation): bool
    {
        return $user->can('admin.workflows.manage') || (int) $delegation->delegator_id === (int) $user->id;
    }
}

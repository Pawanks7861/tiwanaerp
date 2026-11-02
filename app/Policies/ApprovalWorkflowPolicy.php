<?php

namespace App\Policies;

use App\Models\Approval\ApprovalWorkflow;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

class ApprovalWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.workflows.view');
    }

    public function view(User $user, ApprovalWorkflow $workflow): bool
    {
        return $this->owns($workflow) && $user->can('admin.workflows.view');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.workflows.manage');
    }

    public function update(User $user, ApprovalWorkflow $workflow): bool
    {
        return $this->owns($workflow) && $user->can('admin.workflows.manage');
    }

    private function owns(ApprovalWorkflow $workflow): bool
    {
        return (int) $workflow->company_id === (int) app(CurrentCompany::class)->id();
    }
}

<?php

namespace App\Policies\Gate;

use App\Enums\Gate\GatePassStatus;
use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Approval\ApprovalService;
use App\Support\Tenancy\CurrentCompany;

class GatePassPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projects,
        private readonly ApprovalService $approvals,
    ) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('gate_pass.view') && $this->projects->view($user, $project);
    }

    /**
     * Company-wide list (menu) — same company, no project membership required.
     */
    public function viewAnyCompany(User $user): bool
    {
        return $user->can('gate_pass.view');
    }

    public function view(User $user, GatePass $pass): bool
    {
        // View is company-scoped: any active company user with gate_pass.view may open a pass.
        // Create/approve/verify remain project + permission gated.
        return $user->can('gate_pass.view')
            && (int) $pass->company_id === (int) app(CurrentCompany::class)->id();
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('gate_pass.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.create') && $pass->isEditable() && $this->projects->view($user, $pass->project);
    }

    public function delete(User $user, GatePass $pass): bool
    {
        return $this->update($user, $pass);
    }

    public function approve(User $user, GatePass $pass): bool
    {
        if (! $this->projects->view($user, $pass->project) || $pass->status !== GatePassStatus::Submitted) {
            return false;
        }

        $pending = $pass->pendingApprovalRequest();
        if ($pending !== null) {
            return $this->approvals->canAct($pending, $user);
        }

        return $user->can('gate_pass.approve');
    }

    public function verify(User $user, GatePass $pass): bool
    {
        return $user->can('gate_pass.verify') && $this->projects->view($user, $pass->project);
    }
}

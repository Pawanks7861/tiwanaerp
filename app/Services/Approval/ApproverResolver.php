<?php

namespace App\Services\Approval;

use App\Contracts\Approvable;
use App\Enums\Approval\ApproverType;
use App\Enums\ProjectRole;
use App\Models\Approval\ApprovalRequest;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the users eligible to act on an approval level. Only active members of the
 * request's company are ever eligible.
 */
class ApproverResolver
{
    /**
     * @return Collection<int, User>
     */
    public function forLevel(ApprovalRequest $request, int $level): Collection
    {
        $step = $request->stepForLevel($level);
        if ($step === null) {
            return collect();
        }

        $companyId = $request->company_id;
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn (Builder $m) => $m->where('company_id', $companyId)->where('is_active', true));

        switch (ApproverType::from($step['approver_type'])) {
            case ApproverType::User:
                $query->whereKey($step['user_id']);
                break;

            case ApproverType::Role:
                $query->whereExists(function ($sub) use ($step, $companyId) {
                    $sub->selectRaw('1')
                        ->from(config('permission.table_names.model_has_roles'))
                        ->whereColumn('model_id', 'users.id')
                        ->where('model_type', (new User)->getMorphClass())
                        ->where('role_id', $step['role_id'])
                        ->where(config('permission.column_names.team_foreign_key'), $companyId);
                });
                break;

            case ApproverType::ProjectRole:
                /** @var Approvable|null $document */
                $document = $request->approvable;
                $projectId = $document?->approvalProjectId();
                $project = $projectId === null ? null : Project::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $companyId)
                    ->find($projectId, ['id', 'project_manager_id']);
                if ($project === null) {
                    return collect();
                }

                $query->where(function (Builder $q) use ($project, $step) {
                    $q->whereHas('projectMemberships', fn (Builder $pm) => $pm
                        ->where('project_id', $project->id)
                        ->where('project_role', $step['project_role'])
                        ->where('is_active', true));

                    if ($step['project_role'] === ProjectRole::Manager->value && $project->project_manager_id) {
                        $q->orWhere('users.id', $project->project_manager_id);
                    }
                });
                break;
        }

        return $query->orderBy('name')->get();
    }
}

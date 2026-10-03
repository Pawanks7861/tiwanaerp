<?php

namespace App\Policies;

use App\Enums\Boq\RateAnalysisStatus;
use App\Models\Boq\RateAnalysis;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * Rate analyses expose internal costs, so every ability also requires boq.view_costs.
 */
class RateAnalysisPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'rate_analysis.view', $project);
    }

    public function view(User $user, RateAnalysis $analysis): bool
    {
        return $this->allowed($user, 'rate_analysis.view', $analysis->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'rate_analysis.create', $project);
    }

    public function update(User $user, RateAnalysis $analysis): bool
    {
        return $analysis->status === RateAnalysisStatus::Draft && $this->allowed($user, 'rate_analysis.update', $analysis->project);
    }

    public function delete(User $user, RateAnalysis $analysis): bool
    {
        return $analysis->status === RateAnalysisStatus::Draft && $this->allowed($user, 'rate_analysis.delete', $analysis->project);
    }

    public function approve(User $user, RateAnalysis $analysis): bool
    {
        return $analysis->status === RateAnalysisStatus::Draft && $this->allowed($user, 'rate_analysis.approve', $analysis->project);
    }

    private function allowed(User $user, string $permission, ?Project $project): bool
    {
        return $project !== null && $user->can($permission) && $user->can('boq.view_costs') && $this->projects->view($user, $project);
    }
}

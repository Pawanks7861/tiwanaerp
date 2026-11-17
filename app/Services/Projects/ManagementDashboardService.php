<?php

namespace App\Services\Projects;

use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\RevisionChangeOrderStatus;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;
use App\Queries\Reports\ProcurementQuery;
use App\Queries\Reports\QualityCrmQuery;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\ProjectMaterialControlService;
use App\Services\Reports\DashboardService;
use Illuminate\Support\Facades\DB;

/**
 * Admin / MD / Director dashboard payloads. All cost figures come from ProjectCostControlService.
 */
class ManagementDashboardService
{
    public function __construct(
        private readonly ProjectCostControlService $costControl,
        private readonly DashboardService $dashboard,
        private readonly ProcurementQuery $procurement,
        private readonly QualityCrmQuery $quality,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * Highest dashboard profile the user may open (md > director > admin).
     * Manual profile switching is not supported — permission priority only.
     */
    public function resolveProfile(User $user): ?string
    {
        $allowed = $this->allowedProfiles($user);
        if ($allowed === []) {
            return null;
        }

        foreach (['md', 'director', 'admin'] as $profile) {
            if (in_array($profile, $allowed, true)) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allowedProfiles(User $user): array
    {
        $profiles = [];
        if ($user->can('dashboard.md')) {
            $profiles[] = 'md';
        }
        if ($user->can('dashboard.director')) {
            $profiles[] = 'director';
        }
        if ($user->can('dashboard.admin')) {
            $profiles[] = 'admin';
        }

        return $profiles;
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array{from: string, to: string, as_of: string, fy: string, project_id: int|null}  $period
     * @return array<string, mixed>
     */
    public function admin(Company $company, User $user, array $projectIds, array $period): array
    {
        $portfolio = $this->costPayload($user, $company, $projectIds, $period['as_of']);
        $executive = $this->dashboard->executive($company, $user, $projectIds, $period);
        $cid = (int) $company->id;

        $pendingBudget = DB::table('project_budgets')
            ->where('company_id', $cid)
            ->whereIn('project_id', $projectIds ?: [0])
            ->where('status', BudgetStatus::Draft->value)
            ->whereNull('deleted_at')
            ->count();

        $pendingGrn = DB::table('grns')
            ->where('company_id', $cid)
            ->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', ['draft', 'submitted'])
            ->whereNull('deleted_at')
            ->count();

        $pendingBills = DB::table('vendor_bills')
            ->where('company_id', $cid)
            ->whereIn('project_id', $projectIds ?: [0])
            ->whereIn('status', ['draft', 'submitted'])
            ->whereNull('deleted_at')
            ->count();

        $ncr = $this->quality->ncrStatusCounts($cid, $projectIds);

        return [
            'profile' => 'admin',
            'cost_control' => $portfolio,
            'executive' => $executive,
            'operations' => [
                'pending_approvals' => $user->can('approvals.view') ? $this->approvals->pendingCountFor($user) : 0,
                'pending_budget_revisions' => $pendingBudget,
                'pending_grn' => $pendingGrn,
                'vendor_bills_pending' => $pendingBills,
                'open_ncr' => array_sum(array_diff_key($ncr, ['closed' => true])),
                'pending_mr' => collect($portfolio['projects'] ?? [])->sum('pending_mr'),
            ],
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array{from: string, to: string, as_of: string, fy: string, project_id: int|null}  $period
     * @return array<string, mixed>
     */
    public function md(Company $company, User $user, array $projectIds, array $period): array
    {
        $portfolio = $this->costPayload($user, $company, $projectIds, $period['as_of']);
        $cid = (int) $company->id;

        $budgetChanges = [
            'pending_revisions' => DB::table('project_budgets')
                ->where('company_id', $cid)->whereIn('project_id', $projectIds ?: [0])
                ->where('status', BudgetStatus::Draft->value)->whereNull('deleted_at')->count(),
            'approved_boq_variations' => DB::table('boq_revision_change_orders')
                ->where('company_id', $cid)->whereIn('project_id', $projectIds ?: [0])
                ->where('status', RevisionChangeOrderStatus::Approved->value)->whereNull('deleted_at')->count(),
            'pending_boq_variations' => DB::table('boq_revision_change_orders')
                ->where('company_id', $cid)->whereIn('project_id', $projectIds ?: [0])
                ->whereIn('status', [RevisionChangeOrderStatus::Draft->value, RevisionChangeOrderStatus::UnderReview->value])
                ->whereNull('deleted_at')->count(),
        ];

        $cashFlow = null;
        if (count($projectIds) === 1) {
            $project = Project::query()->find($projectIds[0]);
            $cashFlow = $project ? $this->costControl->cashFlow($project) : null;
        }

        return [
            'profile' => 'md',
            'cost_control' => $portfolio,
            'change_control' => $budgetChanges,
            'vendors' => array_slice($portfolio['vendors'] ?? [], 0, 10),
            'cash_flow' => $cashFlow,
            'forecast' => ['fac' => 'DEFERRED', 'etc' => 'DEFERRED', 'eac' => 'DEFERRED', 'vac' => 'DEFERRED'],
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array{from: string, to: string, as_of: string, fy: string, project_id: int|null}  $period
     * @return array<string, mixed>
     */
    public function director(Company $company, User $user, array $projectIds, array $period): array
    {
        $selectedId = $period['project_id'] ?? (count($projectIds) === 1 ? $projectIds[0] : null);
        $scopeIds = $selectedId ? [$selectedId] : $projectIds;
        $portfolio = $this->costPayload($user, $company, $scopeIds, $period['as_of']);

        $projectDetail = null;
        if ($selectedId) {
            $project = Project::query()->find($selectedId);
            if ($project) {
                $summary = $user->can('dashboard.view_financials') || $user->can('boq.view_costs')
                    ? $this->costControl->projectSummary($project, $period['as_of'])
                    : null;
                $material = null;
                if ($user->can('boq.view_reconciliation') || $user->can('reports.view')) {
                    try {
                        $material = app(ProjectMaterialControlService::class)->report($project, ['per_page' => 5]);
                    } catch (\Throwable) {
                        $material = null;
                    }
                }
                $projectDetail = [
                    'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                    'cost_control' => $summary,
                    'cash_flow' => $summary ? $this->costControl->cashFlow($project) : null,
                    'material_control' => $material,
                    'procurement' => [
                        'open_po_value' => $this->procurement->purchaseValue($company->id, [$project->id], $period['from'], $period['to']),
                    ],
                    'ncr' => $this->quality->ncrStatusCounts((int) $company->id, [$project->id]),
                ];
            }
        }

        return [
            'profile' => 'director',
            'cost_control' => $portfolio,
            'selected_project_id' => $selectedId,
            'project_detail' => $projectDetail,
            'forecast' => ['fac' => 'DEFERRED', 'etc' => 'DEFERRED', 'eac' => 'DEFERRED', 'vac' => 'DEFERRED'],
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private function costPayload(User $user, Company $company, array $projectIds, ?string $asOf): array
    {
        if (! $user->can('dashboard.view_financials') && ! $user->can('boq.view_costs')) {
            return [
                'redacted' => true,
                'kpis' => null,
                'projects' => [],
                'cost_heads' => [],
                'vendors' => [],
            ];
        }

        return $this->costControl->portfolio((int) $company->id, $projectIds, $asOf);
    }
}

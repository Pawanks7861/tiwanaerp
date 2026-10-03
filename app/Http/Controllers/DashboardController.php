<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company dashboard. Phase 1 shows only data that exists in Phase 1 (projects, approvals).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canFinancials = $user->can('dashboard.view_financials');

        $visible = Project::query()->visibleTo($user);
        $byStatus = (clone $visible)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $recent = (clone $visible)
            ->with('projectManager:id,name')
            ->whereIn('status', [ProjectStatus::Planning, ProjectStatus::Active, ProjectStatus::OnHold])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'city' => $p->city,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'manager_name' => $p->projectManager?->name,
                'contract_value' => $canFinancials ? $p->contract_value : null,
                'expected_end_date' => $p->expected_end_date?->toDateString(),
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'active' => (int) ($byStatus[ProjectStatus::Active->value] ?? 0),
                'planning' => (int) ($byStatus[ProjectStatus::Planning->value] ?? 0),
                'on_hold' => (int) ($byStatus[ProjectStatus::OnHold->value] ?? 0),
                'completed' => (int) ($byStatus[ProjectStatus::Completed->value] ?? 0),
                'pending_approvals' => $user->can('approvals.view') ? $this->approvals->pendingFor($user)->count() : null,
                'contract_value' => $canFinancials
                    ? Decimal::sum((clone $visible)
                        ->whereIn('status', [ProjectStatus::Active, ProjectStatus::Planning, ProjectStatus::OnHold])
                        ->get(['id', 'contract_value'])
                        ->map(fn (Project $p) => $p->contract_value))->toMoney()
                    : null,
            ],
            'recentProjects' => $recent->all(),
            'statusBreakdown' => collect(ProjectStatus::cases())->map(fn ($s) => [
                'label' => $s->label(),
                'value' => (int) ($byStatus[$s->value] ?? 0),
            ])->all(),
        ]);
    }
}

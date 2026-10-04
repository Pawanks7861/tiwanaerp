<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use App\Reports\ReportRunner;
use App\Services\Approval\ApprovalService;
use App\Services\Reports\DashboardService;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive dashboard (architecture O.5). KPIs and charts need dashboard.view; money needs
 * dashboard.view_financials and is never computed or sent otherwise. Scope = projects the user
 * can see in the current company, optionally one project, for a financial year or date range.
 * KPIs are cached per filter / permission set; pending approvals are per user and never cached.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly DashboardService $dashboards,
        private readonly ReportRunner $runner,
        private readonly CurrentCompany $current,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $company = $this->current->require();
        $visible = $this->runner->visibleProjectIds($user);
        $fyOptions = ReportPeriod::options($company);

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::in($visible)],
            'fy' => ['nullable', Rule::in(array_column($fyOptions, 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], filled($request->query('from')) ? ['after_or_equal:from'] : []),
        ]);

        $fy = filled($data['fy'] ?? null) ? ReportPeriod::find($company, $data['fy']) : ReportPeriod::current($company);
        $today = ReportPeriod::today($company);
        $from = $data['from'] ?? $fy['start'];
        $to = $data['to'] ?? $fy['end'];
        $period = [
            'fy' => $fy['value'], 'from' => $from, 'to' => $to, 'as_of' => min($to, $today),
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
        ];
        $projectIds = $period['project_id'] ? [$period['project_id']] : $visible;

        $recent = Project::query()->visibleTo($user)->with('projectManager:id,name')
            ->whereIn('status', [ProjectStatus::Planning, ProjectStatus::Active, ProjectStatus::OnHold])
            ->latest('id')->limit(6)->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'city' => $p->city,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'manager_name' => $p->projectManager?->name,
                'expected_end_date' => $p->expected_end_date?->toDateString(),
            ]);

        return Inertia::render('Dashboard', [
            'dashboard' => $user->can('dashboard.view') ? $this->dashboards->executive($company, $user, $projectIds, $period) : null,
            'pendingApprovals' => $user->can('approvals.view') ? $this->approvals->pendingFor($user)->count() : null,
            'recentProjects' => $recent->all(),
            'filters' => [
                'project_id' => $period['project_id'],
                'fy' => $fy['value'],
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ],
            'period' => ['label' => $from === $fy['start'] && $to === $fy['end'] ? $fy['label'] : date('d M Y', strtotime($from)).' – '.date('d M Y', strtotime($to)),
                'as_of' => $period['as_of'], 'from' => $from, 'to' => $to],
            'options' => [
                'fy' => array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']], $fyOptions),
                'projects' => Project::query()->visibleTo($user)->orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn (Project $p) => ['value' => $p->id, 'label' => "{$p->code} — {$p->name}"])->all(),
            ],
            'can' => [
                'dashboard' => $user->can('dashboard.view'),
                'financials' => $user->can('dashboard.view_financials'),
                'reports' => $user->can('reports.view'),
            ],
        ]);
    }
}

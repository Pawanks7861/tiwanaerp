<?php

namespace App\Http\Controllers\Projects;

use App\Exports\ProjectCostControlExport;
use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectCashFlowPlan;
use App\Models\Projects\ProjectCashFlowPlanMonth;
use App\Services\Projects\ProjectCostControlService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectCostControlController extends Controller
{
    public function __construct(
        private readonly ProjectCostControlService $costControl,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('budget.view') && $request->user()->can('dashboard.view_financials'), 403);

        $asOf = $request->query('as_of');
        $summary = $this->costControl->projectSummary($project, is_string($asOf) && $asOf !== '' ? $asOf : null);
        $cashFlow = $this->costControl->cashFlow($project);
        $vendors = $this->costControl->vendorExposure((int) $project->company_id, [(int) $project->id], $summary['as_of']);

        return Inertia::render('Projects/CostControl/Index', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
            ],
            'summary' => $summary,
            'cashFlow' => $cashFlow,
            'vendors' => $vendors,
            'filters' => ['as_of' => $asOf],
            'can' => [
                'manageBudget' => $request->user()->can('budget.update'),
                'manageCashFlow' => $request->user()->can('budget.update'),
                'export' => $request->user()->can('reports.export'),
                'viewCosts' => $request->user()->can('boq.view_costs'),
            ],
        ]);
    }

    public function head(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('budget.view') && $request->user()->can('dashboard.view_financials'), 403);

        $headParam = $request->route('head');
        $resolved = ($headParam === null || $headParam === 'unallocated') ? null : (int) $headParam;

        return Inertia::render('Projects/CostControl/Head', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
            ],
            'headId' => $resolved,
            'drilldown' => $this->costControl->drilldown($project, $resolved),
        ]);
    }

    public function export(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless(
            $request->user()->can('reports.export')
            && $request->user()->can('dashboard.view_financials'),
            403,
        );

        $type = $request->query('type', 'heads');
        $summary = $this->costControl->projectSummary($project);
        $asOf = $summary['as_of'] ?? now()->toDateString();

        if ($type === 'vendors') {
            $vendors = $this->costControl->vendorExposure((int) $project->company_id, [(int) $project->id], is_string($asOf) ? $asOf : null);
            $rows = [[
                'Vendor', 'Base Orders', 'Gross Orders', 'Actual Invoiced', 'Open Commitment',
                'Retention Held', 'Cash Paid', 'Balance Payable', 'Status',
            ]];
            foreach ($vendors as $vendor) {
                $rows[] = [
                    $vendor['vendor_name'] ?? '',
                    $vendor['total_base_orders'] ?? '0.00',
                    $vendor['gross_orders'] ?? '0.00',
                    $vendor['actual_invoiced'] ?? '0.00',
                    $vendor['open_commitment'] ?? '0.00',
                    $vendor['retention_held'] ?? '0.00',
                    $vendor['net_cash_paid'] ?? '0.00',
                    $vendor['balance_payable'] ?? '0.00',
                    $vendor['status'] ?? '',
                ];
            }

            return Excel::download(
                new ProjectCostControlExport($rows, 'Vendor Exposure'),
                $project->code.'-vendor-exposure.xlsx',
            );
        }

        $rows = [[
            'Cost Head', 'Code', 'Original', 'Approved Changes', 'Revised', 'Actual Invoiced',
            'Open Commitment', 'Total Assigned', 'Available', '% Assigned', 'GST Exposure',
        ]];
        foreach ([...$summary['heads'], $summary['unallocated']] as $head) {
            $rows[] = [
                $head['name'] ?? '',
                $head['code'] ?? '',
                $head['original_budget'] ?? '0.00',
                $head['approved_changes'] ?? '0.00',
                $head['revised_budget'] ?? '0.00',
                $head['actual_invoiced'] ?? '0.00',
                $head['open_commitment'] ?? '0.00',
                $head['total_assigned'] ?? '0.00',
                $head['available_budget'] ?? '0.00',
                $head['percent_assigned'] ?? '',
                $head['gst_exposure'] ?? '0.00',
            ];
        }

        return Excel::download(
            new ProjectCostControlExport($rows, 'Cost Head Summary'),
            $project->code.'-cost-control.xlsx',
        );
    }

    public function saveCashFlowPlan(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('budget.update'), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'months' => ['required', 'array', 'min:1'],
            'months.*.year_month' => ['required', 'date_format:Y-m'],
            'months.*.planned_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
        ]);

        DB::transaction(function () use ($project, $data) {
            ProjectCashFlowPlan::query()
                ->where('project_id', $project->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $total = Decimal::sum(array_map(
                fn ($m) => (string) $m['planned_amount'],
                $data['months'],
            ));

            $plan = new ProjectCashFlowPlan;
            $plan->forceFill([
                'project_id' => $project->id,
                'name' => $data['name'] ?? 'Cash Flow Plan',
                'is_active' => true,
                'total_planned' => $total->toMoney(),
            ])->save();

            foreach ($data['months'] as $month) {
                (new ProjectCashFlowPlanMonth)->forceFill([
                    'project_cash_flow_plan_id' => $plan->id,
                    'year_month' => $month['year_month'],
                    'planned_amount' => Decimal::of((string) $month['planned_amount'])->toMoney(),
                ])->save();
            }
        });

        return back()->with('success', 'Cash flow plan saved.');
    }
}

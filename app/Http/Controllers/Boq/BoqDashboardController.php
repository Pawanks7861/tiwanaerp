<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Projects\Project;
use App\Services\Boq\BoqIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoqDashboardController extends Controller
{
    public function __construct(private readonly BoqIntelligenceService $intel) {}

    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view'), 403);

        $canCosts = $request->user()->can('boq.view_costs');
        $dash = $this->intel->dashboard($project, $canCosts);

        return Inertia::render('Boq/Dashboard', [
            'project' => ProjectHeader::for($project),
            'cards' => $dash['cards'],
            'contract' => $dash['contract'],
            'health' => $dash['health'],
            'variation_widget' => $dash['variation_widget'],
            'can' => [
                'view_costs' => $canCosts,
                'view_variations' => $request->user()->can('boq.view_variations'),
                'view_materials' => $request->user()->can('boq.view_material_requirements'),
                'view_reconciliation' => $request->user()->can('boq.view_reconciliation'),
                'view_reports' => $request->user()->can('reports.view'),
                'view_financial_reports' => $request->user()->can('reports.view_financial'),
            ],
            'links' => [
                'item_analysis' => route('reports.project', [$project, 'boq-item-analysis'], absolute: false),
                'progress_billing' => route('reports.project', [$project, 'boq-progress-billing'], absolute: false),
                'estimated_actual' => route('reports.project', [$project, 'estimated-vs-actual-cost'], absolute: false),
                'measurements' => route('reports.project', [$project, 'boq-measurement-summary'], absolute: false),
                'cost_buildup' => route('reports.project', [$project, 'boq-cost-buildup'], absolute: false),
                'procurement_gap' => route('reports.project', [$project, 'boq-procurement-gap'], absolute: false),
                'health_risk' => route('reports.project', [$project, 'boq-health-risk'], absolute: false),
                'variations' => route('projects.variations.index', $project, absolute: false),
                'material_requirement' => route('projects.material-requirement.index', $project, absolute: false),
                'material_reconciliation' => route('projects.material-reconciliation.index', $project, absolute: false),
                'steel_reconciliation' => route('projects.steel-reconciliation.index', $project, absolute: false),
            ],
        ]);
    }
}

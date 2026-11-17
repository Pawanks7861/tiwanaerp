<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Services\Projects\ProjectCostControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectCostControlApiController extends Controller
{
    public function __construct(
        private readonly ProjectCostControlService $costControl,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless(
            $request->user()->can('budget.view')
            && ($request->user()->can('dashboard.view_financials') || $request->user()->can('boq.view_costs')),
            403
        );

        $asOf = $request->query('as_of');
        $summary = $this->costControl->projectSummary(
            $project,
            is_string($asOf) && $asOf !== '' ? $asOf : null,
        );

        if (! $request->user()->can('boq.view_costs') && ! $request->user()->can('dashboard.view_financials')) {
            return response()->json(['data' => ['redacted' => true]]);
        }

        return response()->json([
            'data' => $summary,
        ]);
    }
}

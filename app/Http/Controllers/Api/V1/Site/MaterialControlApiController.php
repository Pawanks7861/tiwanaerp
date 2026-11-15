<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Services\Boq\ProjectMaterialControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaterialControlApiController extends Controller
{
    public function __construct(private readonly ProjectMaterialControlService $control) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation') || $request->user()->can('reports.view'), 403);

        $filters = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:100'],
            'budget_version_id' => ['nullable', 'integer'],
            'boq_id' => ['nullable', 'integer'],
            'over_consumption' => ['nullable', 'boolean'],
            'unestimated_only' => ['nullable', 'boolean'],
            'as_of_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->control->report($project, $filters);
        if (! $request->user()->can('boq.view_costs')) {
            $result = $this->control->redactCosts($result);
        }

        return response()->json($result);
    }

    public function show(Request $request, Project $project, Material $material): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation') || $request->user()->can('reports.view'), 403);
        abort_unless((int) $material->company_id === (int) $project->company_id, 404);

        $filters = $request->validate([
            'budget_version_id' => ['nullable', 'integer'],
            'boq_id' => ['nullable', 'integer'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $detail = $this->control->detail($project, $material->id, $filters);
        if (! $request->user()->can('boq.view_costs')) {
            if (isset($detail['summary']) && is_array($detail['summary'])) {
                $redacted = $this->control->redactCosts(['rows' => [$detail['summary']], 'summary' => []]);
                $detail['summary'] = $redacted['rows'][0] ?? null;
            }
        }

        return response()->json($detail);
    }
}

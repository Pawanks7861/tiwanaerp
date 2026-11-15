<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Projects\Project;
use App\Services\Boq\AdvancedBoqRevisionComparisonService;
use App\Support\Boq\BoqRevisionPairResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoqRevisionCompareController extends Controller
{
    public function __construct(
        private readonly AdvancedBoqRevisionComparisonService $comparison,
        private readonly BoqRevisionPairResolver $pairs,
    ) {}

    public function page(Request $request, Project $project, Boq $boq): Response
    {
        Gate::authorize('view', $boq);

        [$from, $to] = $this->pairs->resolve($project, $boq, $request);
        Gate::authorize('view', $from);

        $user = $request->user();
        $withCosts = $user->can('boq.view_costs');
        $payload = $this->comparison->compare($from, $to, $withCosts);

        $revisions = Boq::query()
            ->where('project_id', $project->id)
            ->where('boq_number', $boq->boq_number)
            ->orderByDesc('version')
            ->get(['id', 'version', 'status', 'is_current']);

        return Inertia::render('Boq/RevisionCompare', [
            'project' => ProjectHeader::for($project),
            'boq' => BoqPresenter::header($boq, $withCosts),
            'comparison' => $payload,
            'from_boq_id' => $from->id,
            'to_boq_id' => $to->id,
            'revisions' => $revisions->map(fn (Boq $b) => [
                'id' => $b->id,
                'version' => $b->version,
                'status' => $b->status->value,
                'is_current' => $b->is_current,
            ])->all(),
            'can' => [
                'view_costs' => $withCosts,
                'create_change_order' => $user->can('create', [BoqRevisionChangeOrder::class, $project]),
            ],
        ]);
    }

    public function json(Request $request, Project $project, Boq $boq): JsonResponse
    {
        Gate::authorize('view', $boq);

        [$from, $to] = $this->pairs->resolve($project, $boq, $request);
        Gate::authorize('view', $from);

        $withCosts = $request->user()->can('boq.view_costs');
        $payload = $this->comparison->compare($from, $to, $withCosts);

        return response()->json($payload);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourAdvanceResource;
use App\Models\Labour\LabourAdvance;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Models\Labour\Labour;
use App\Services\Labour\LabourAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LabourAdvanceApiController extends Controller
{
    public function __construct(private readonly LabourAdvanceService $advances) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [LabourAdvance::class, $project]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $rows = LabourAdvance::query()
            ->where('project_id', $project->id)
            ->with('labour:id,code,name')
            ->latest('advance_date')
            ->latest('id')
            ->paginate($perPage);

        return LabourAdvanceResource::collection($rows);
    }

    public function show(Project $project, LabourAdvance $advance): LabourAdvanceResource
    {
        abort_unless((int) $advance->project_id === (int) $project->id, 404);
        Gate::authorize('viewAny', [LabourAdvance::class, $project]);

        return new LabourAdvanceResource($advance->load('labour:id,code,name'));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [LabourAdvance::class, $project]);

        $data = $request->validate([
            'labour_id' => ['required', 'integer', new ExistsInCompany(Labour::class, fn ($q) => $q->active())],
            'advance_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['labour_id' => 'labourer']);

        $advance = $this->advances->create($project, $data);

        return (new LabourAdvanceResource($advance->load('labour:id,code,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(LabourAdvance $advance): JsonResponse
    {
        Gate::authorize('delete', $advance);
        $this->advances->delete($advance);

        return response()->json(['message' => 'Advance deleted.']);
    }
}

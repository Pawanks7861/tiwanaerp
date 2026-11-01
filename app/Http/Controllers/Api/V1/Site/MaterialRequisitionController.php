<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialRequisitionResource;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MaterialRequisitionController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialRequest::class, $project]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $requests = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with(['site:id,name', 'requester:id,name'])
            ->withCount('items')
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return MaterialRequisitionResource::collection($requests);
    }

    public function show(Project $project, int $material_requisition): MaterialRequisitionResource
    {
        $request = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with([
                'site:id,name',
                'requester:id,name',
                'items.item:id,code,name',
                'items.unit:id,symbol',
            ])
            ->findOrFail($material_requisition);

        Gate::authorize('view', $request);

        return new MaterialRequisitionResource($request);
    }
}

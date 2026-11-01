<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Procurement\MaterialRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialRequisitionResource;
use App\Models\Approval\ApprovalRequest;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('approvals.view');

        $ids = $this->approvals->pendingFor($request->user())
            ->filter(fn (ApprovalRequest $row) => $row->approvable instanceof MaterialRequest
                && (int) $row->approvable->project_id === (int) $project->id)
            ->map(fn (ApprovalRequest $row) => (int) $row->approvable_id)
            ->all();

        $pending = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->where('status', MaterialRequestStatus::Submitted)
            ->whereIn('id', $ids)
            ->with(['site:id,name', 'requester:id,name'])
            ->withCount('items')
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->get();

        return MaterialRequisitionResource::collection($pending);
    }

    public function approve(Request $request, Project $project, int $id): JsonResponse
    {
        $comments = $request->validate(['comments' => ['nullable', 'string', 'max:2000']])['comments'] ?? null;
        $this->approvals->approve($this->pendingRequest($project, $id), $request->user(), $comments);

        return response()->json([
            'message' => 'Approved.',
            'data' => new MaterialRequisitionResource($this->fresh($project, $id)),
        ]);
    }

    public function reject(Request $request, Project $project, int $id): JsonResponse
    {
        $comments = $request->validate(['comments' => ['required', 'string', 'max:2000']])['comments'];
        $this->approvals->reject($this->pendingRequest($project, $id), $request->user(), $comments);

        return response()->json([
            'message' => 'Rejected.',
            'data' => new MaterialRequisitionResource($this->fresh($project, $id)),
        ]);
    }

    private function pendingRequest(Project $project, int $id): ApprovalRequest
    {
        Gate::authorize('approvals.view');

        $mr = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->findOrFail($id);

        return $mr->pendingApprovalRequest() ?? abort(404);
    }

    private function fresh(Project $project, int $id): MaterialRequest
    {
        return MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with(['site:id,name', 'requester:id,name'])
            ->withCount('items')
            ->findOrFail($id);
    }
}

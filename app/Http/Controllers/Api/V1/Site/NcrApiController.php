<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Quality\NcrSeverity;
use App\Enums\Quality\NcrStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NcrResource;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Services\Quality\NcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NcrApiController extends Controller
{
    public function __construct(private readonly NcrService $ncrs) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Ncr::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(NcrStatus::class)],
            'severity' => ['nullable', Rule::enum(NcrSeverity::class)],
            'responsible_user_id' => ['nullable', 'integer'],
            'quality_inspection_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'due_before' => ['nullable', 'date'],
            'due_after' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $today = now()->toDateString();

        $page = $project->ncrs()
            ->with(['project:id,company_id', 'responsibleUser:id,name', 'subcontractor:id,name', 'inspection:id,inspection_number'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['responsible_user_id'] ?? null, fn ($q, $id) => $q->where('responsible_user_id', $id))
            ->when($filters['quality_inspection_id'] ?? null, fn ($q, $id) => $q->where('quality_inspection_id', $id))
            ->when($filters['due_before'] ?? null, fn ($q, $d) => $q->whereDate('target_date', '<=', $d))
            ->when($filters['due_after'] ?? null, fn ($q, $d) => $q->whereDate('target_date', '>=', $d))
            ->when(array_key_exists('overdue', $filters) && $request->boolean('overdue'), fn ($q) => $q
                ->whereNotNull('target_date')
                ->whereDate('target_date', '<', $today)
                ->whereNotIn('status', [NcrStatus::Verified->value, NcrStatus::Closed->value]))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('ncr_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('issue', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('location', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return NcrResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Ncr::class, $project]);

        $data = $request->validate([
            'quality_inspection_id' => ['nullable', 'integer'],
            ...$this->detailRules(),
        ], [], ['quality_inspection_id' => 'inspection']);

        $ncr = $this->ncrs->create($project, $data);

        return (new NcrResource($this->detail($ncr)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('view', $ncr);

        return new NcrResource($this->detail($ncr));
    }

    public function update(Request $request, Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('update', $ncr);

        $this->ncrs->update($ncr, $request->validate($this->detailRules()));

        return new NcrResource($this->detail($ncr->fresh()));
    }

    public function destroy(Project $project, Ncr $ncr): JsonResponse
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('delete', $ncr);

        $this->ncrs->delete($ncr);

        return response()->json(['message' => 'NCR deleted.']);
    }

    public function start(Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('start', $ncr);

        $this->ncrs->start($ncr);

        return new NcrResource($this->detail($ncr->fresh()));
    }

    public function resolve(Request $request, Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('resolve', $ncr);

        $data = $request->validate([
            'root_cause' => ['required', 'string', 'max:2000'],
            'corrective_action' => ['required', 'string', 'max:2000'],
        ]);
        $this->ncrs->resolve($ncr, $data, $request->user());

        return new NcrResource($this->detail($ncr->fresh()));
    }

    public function verify(Request $request, Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('verify', $ncr);

        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']])['remarks'] ?? null;
        $this->ncrs->verify($ncr, $remarks, $request->user());

        return new NcrResource($this->detail($ncr->fresh()));
    }

    public function reopen(Request $request, Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('reopen', $ncr);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $this->ncrs->reopen($ncr, $reason, $request->user());

        return new NcrResource($this->detail($ncr->fresh()));
    }

    public function close(Request $request, Project $project, Ncr $ncr): NcrResource
    {
        $this->assertNcr($project, $ncr);
        Gate::authorize('close', $ncr);

        $this->ncrs->close($ncr, $request->user());

        return new NcrResource($this->detail($ncr->fresh()));
    }

    private function detail(Ncr $ncr): Ncr
    {
        return $ncr->load([
            'project:id,company_id',
            'inspection:id,inspection_number,result',
            'responsibleUser:id,name',
            'subcontractor:id,name,code',
            'resolver:id,name',
            'verifier:id,name',
            'closer:id,name',
            'creator:id,name',
            'attachments.uploader:id,name',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRules(): array
    {
        return [
            'issue' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(NcrSeverity::class)],
            'responsible_user_id' => ['nullable', 'integer'],
            'subcontractor_id' => ['nullable', 'integer'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    private function assertNcr(Project $project, Ncr $ncr): void
    {
        abort_unless((int) $ncr->project_id === (int) $project->id, 404);
    }
}

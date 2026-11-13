<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassItemType;
use App\Enums\Gate\GatePassStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GatePassResource;
use App\Models\Core\Company;
use App\Models\Gate\GatePass;
use App\Models\Projects\Project;
use App\Services\Gate\GatePassService;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use App\Support\Pdf\PdfResponder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GatePassApiController extends Controller
{
    public function __construct(private readonly GatePassService $passes) {}

    public function summary(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [GatePass::class, $project]);

        $rows = GatePass::query()
            ->where('project_id', $project->id)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $buckets = ['draft', 'submitted', 'approved', 'verified', 'closed', 'rejected'];
        $counts = ['total' => (int) $rows->sum()];
        foreach ($buckets as $bucket) {
            $counts[$bucket] = (int) ($rows[$bucket] ?? 0);
        }
        $counts['pending_approval'] = $counts['submitted'];
        $counts['awaiting_verification'] = $counts['approved'];

        $user = $request->user();

        return response()->json([
            'data' => $counts + [
                'can' => [
                    'create' => $user->can('create', [GatePass::class, $project]),
                ],
            ],
        ]);
    }

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [GatePass::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(GatePassStatus::class)],
            'direction' => ['nullable', Rule::enum(GatePassDirection::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = $project->gatePasses()
            ->with(['site:id,name', 'vendor:id,name'])
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['direction']), fn ($q) => $q->where('direction', $filters['direction']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('pass_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('party_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('vehicle_no', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('purpose', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('passed_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return GatePassResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [GatePass::class, $project]);

        $pass = $this->passes->create($project, $this->validated($request));

        return (new GatePassResource($this->detail($pass)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('view', $gatePass);

        return new GatePassResource($this->detail($gatePass));
    }

    public function update(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('update', $gatePass);

        $pass = $this->passes->update($gatePass, $this->validated($request));

        return new GatePassResource($this->detail($pass));
    }

    public function destroy(Project $project, GatePass $gatePass): JsonResponse
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('delete', $gatePass);

        $this->passes->delete($gatePass);

        return response()->json(['message' => 'Gate pass deleted.']);
    }

    public function submit(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('update', $gatePass);

        $this->passes->submit($gatePass, $request->user());

        return new GatePassResource($this->detail($gatePass->fresh()));
    }

    public function approve(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('approve', $gatePass);

        $this->passes->approve($gatePass, $request->user());

        return new GatePassResource($this->detail($gatePass->fresh()));
    }

    public function reject(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('approve', $gatePass);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->passes->reject($gatePass, $request->user(), $reason);

        return new GatePassResource($this->detail($gatePass->fresh()));
    }

    public function verify(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('verify', $gatePass);

        $this->passes->verify($gatePass, $request->user());

        return new GatePassResource($this->detail($gatePass->fresh()));
    }

    public function returned(Request $request, Project $project, GatePass $gatePass): GatePassResource
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('verify', $gatePass);

        $this->passes->markReturned($gatePass, $request->user());

        return new GatePassResource($this->detail($gatePass->fresh()));
    }

    public function pdf(Request $request, Project $project, GatePass $gatePass): Response
    {
        $this->assertOnProject($project, $gatePass);
        Gate::authorize('view', $gatePass);

        $gatePass->load(['items.unit:id,symbol', 'site:id,name', 'weighbridgeReceipt', 'approver:id,name', 'verifier:id,name']);

        $pdf = Pdf::loadView('pdf.gate-pass', [
            'company' => Company::query()->findOrFail($gatePass->company_id),
            'project' => $project,
            'pass' => $gatePass,
        ]);

        $inline = $request->routeIs('api.v1.projects.gate-passes.pdf.view');

        return PdfResponder::send($pdf, $gatePass->pass_number.'.pdf', $inline);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer'],
            'direction' => ['required', Rule::enum(GatePassDirection::class)],
            'returnable' => ['boolean'],
            'passed_at' => ['required', 'date'],
            'expected_return_date' => ['nullable', 'date'],
            'party_name' => ['nullable', 'string', 'max:150'],
            'vendor_id' => ['nullable', 'integer'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'purpose' => ['required', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_type' => ['required', Rule::enum(GatePassItemType::class)],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.equipment_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:300'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.weight' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.scrap_type' => ['nullable', 'string', 'max:80'],
            'items.*.scrap_type_id' => ['nullable', 'integer'],
            'items.*.rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.destination' => ['nullable', 'string', 'max:150'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
            ...WeighbridgeReceiptService::rules(),
        ]);
    }

    private function detail(GatePass $pass): GatePass
    {
        return $pass->load([
            'site:id,name',
            'vendor:id,name',
            'approver:id,name',
            'verifier:id,name',
            'items.unit:id,symbol',
            'items.material:id,code,name',
            'items.equipment:id,code,name',
            'weighbridgeReceipt',
            'attachments.uploader:id,name',
        ]);
    }

    private function assertOnProject(Project $project, GatePass $pass): void
    {
        abort_unless((int) $pass->project_id === (int) $project->id, 404);
    }
}

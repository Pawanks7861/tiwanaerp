<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\SiteExecution\DprStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SiteExecution\DprRequest;
use App\Http\Resources\DprResource;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Services\SiteExecution\DprPrefillService;
use App\Services\SiteExecution\DprService;
use App\Support\Format\IndianNumber;
use App\Support\Pdf\PdfResponder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DprController extends Controller
{
    public function __construct(
        private readonly DprService $dprs,
        private readonly DprPrefillService $prefill,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Dpr::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DprStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = Dpr::query()
            ->where('project_id', $project->id)
            ->with('engineer:id,name')
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('dpr_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('dpr_date', '<=', $filters['to']))
            ->when($search !== '', fn ($q) => $q->where('dpr_number', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('dpr_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return DprResource::collection($rows);
    }

    public function prefill(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Dpr::class, $project]);

        $validated = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'include_completed' => ['nullable', 'boolean'],
        ]);
        $date = substr((string) ($validated['date'] ?? now()->toDateString()), 0, 10);
        $includeCompleted = $request->boolean('include_completed');

        $existing = Dpr::query()
            ->where('project_id', $project->id)
            ->whereDate('dpr_date', $date)
            ->first(['id', 'dpr_number', 'status']);

        $content = $this->prefill->forDateMergingLegacy($project, $date, $includeCompleted);

        return response()->json([
            'data' => [
                'date' => $date,
                'include_completed' => $includeCompleted,
                'existing' => $existing ? [
                    'id' => $existing->id,
                    'dpr_number' => $existing->dpr_number,
                    'status' => $existing->status?->value ?? $existing->status,
                ] : null,
                'source' => $content['source'],
                'diary_ids' => $content['diary_ids'] ?? [],
                'weather' => $content['weather'],
                'site_issues' => $content['site_issues'],
                'items' => $content['items'],
                'labours' => $content['labours'],
                'equipment' => $content['equipment'],
                'materials' => $content['materials'],
                'constraints' => $content['constraints'],
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Dpr::class, $project]);

        $data = $request->validate([
            'dpr_date' => ['required', 'date', 'before_or_equal:today'],
            'engineer_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'include_completed' => ['nullable', 'boolean'],
        ]);

        $dpr = $this->dprs->create($project, $data);

        return (new DprResource($this->detail($dpr)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('view', $dpr);

        return new DprResource($this->detail($dpr));
    }

    public function update(DprRequest $request, Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);

        $updated = $this->dprs->update($dpr, $request->validated());

        return new DprResource($this->detail($updated));
    }

    public function refresh(Request $request, Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('update', $dpr);
        $updated = $this->dprs->refresh($dpr, $request->boolean('include_completed'));

        return new DprResource($this->detail($updated));
    }

    public function submit(Request $request, Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('submit', $dpr);
        $this->dprs->submit($dpr, $request->user());

        return new DprResource($this->detail($dpr->fresh()));
    }

    public function reopen(Request $request, Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('reopen', $dpr);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->dprs->reopen($dpr, $request->user(), $reason);

        return new DprResource($this->detail($dpr->fresh()));
    }

    public function pdf(Request $request, Project $project, Dpr $dpr): Response
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('export', $dpr);

        $dpr->load(['engineer:id,name', 'approver:id,name']);

        $pdf = Pdf::loadView('pdf.dpr', [
            'dpr' => $dpr,
            'company' => Company::query()->findOrFail($dpr->company_id),
            'project' => $project,
            'items' => $dpr->items()->with(['task:id,wbs_code,name', 'boqItem:id,item_code,name', 'unit:id,symbol'])->get(),
            'labours' => $dpr->labours()->with(['trade:id,name', 'subcontractor:id,name'])->get(),
            'equipment' => $dpr->equipment()->with('equipmentType:id,name')->get(),
            'materials' => $dpr->materials()->with(['material:id,code,name', 'unit:id,symbol'])->get(),
            'constraints' => $dpr->constraints()->with('task:id,wbs_code,name')->get(),
            'qty' => fn ($v) => $v === null ? '—' : IndianNumber::quantity($v),
            'pct' => fn ($v) => $v === null ? '—' : IndianNumber::quantity($v).'%',
        ])->setPaper('a4', 'landscape');

        $inline = $request->routeIs('api.v1.projects.dprs.pdf.view');

        return PdfResponder::send($pdf, "{$dpr->dpr_number}.pdf", $inline);
    }

    private function detail(Dpr $dpr): Dpr
    {
        return $dpr->load([
            'engineer:id,name',
            'items.task:id,wbs_code,name',
            'items.boqItem:id,item_code,name,line_uid',
            'items.unit:id,symbol',
            'labours.trade:id,name',
            'labours.subcontractor:id,name',
            'equipment.equipmentType:id,name',
            'materials.material:id,code,name',
            'materials.unit:id,symbol',
            'constraints.task:id,wbs_code,name',
            'attachments.uploader:id,name',
            'latestApprovalRequest.submitter:id,name',
            'latestApprovalRequest.actions.user:id,name',
        ])->loadCount('items');
    }

    private function assertOnProject(Project $project, Dpr $dpr): void
    {
        abort_unless((int) $dpr->project_id === (int) $project->id, 404);
    }
}

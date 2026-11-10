<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\SiteExecution\DprStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DprResource;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\SiteDiary;
use App\Services\SiteExecution\DprAggregationService;
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
        private readonly DprAggregationService $aggregation,
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

        $date = substr((string) ($request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ])['date'] ?? now()->toDateString()), 0, 10);

        $existing = Dpr::query()
            ->where('project_id', $project->id)
            ->whereDate('dpr_date', $date)
            ->first(['id', 'dpr_number', 'status']);

        $aggregate = $this->aggregation->aggregate($project, $date);
        $diaries = SiteDiary::query()
            ->where('project_id', $project->id)
            ->whereDate('diary_date', $date)
            ->with(['site:id,name', 'creator:id,name'])
            ->withCount('workItems')
            ->orderBy('id')
            ->get()
            ->map(fn (SiteDiary $d) => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'site' => $d->site?->name ?? $d->work_location,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'created_by' => $d->creator?->name,
                'work_items_count' => $d->work_items_count,
                'approved' => $d->status->value === 'approved',
            ]);

        return response()->json([
            'data' => [
                'date' => $date,
                'existing' => $existing ? [
                    'id' => $existing->id,
                    'dpr_number' => $existing->dpr_number,
                    'status' => $existing->status?->value ?? $existing->status,
                ] : null,
                'diary_ids' => $aggregate['diary_ids'],
                'diaries' => $diaries,
                'weather' => $aggregate['weather'],
                'site_issues' => $aggregate['site_issues'],
                'items' => $aggregate['items'],
                'labours' => $aggregate['labours'],
                'equipment' => $aggregate['equipment'],
                'materials' => $aggregate['materials'],
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

    public function update(Request $request, Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('update', $dpr);

        $data = $request->validate([
            'engineer_id' => ['nullable', 'integer'],
            'weather' => ['nullable', 'string', 'max:50'],
            'site_issues' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.executed_qty' => ['required_with:items', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'labours' => ['nullable', 'array', 'max:200'],
            'labours.*.labour_trade_id' => ['required', 'integer'],
            'labours.*.subcontractor_id' => ['nullable', 'integer'],
            'labours.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'labours.*.hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'labours.*.remarks' => ['nullable', 'string', 'max:255'],
            'equipment' => ['nullable', 'array', 'max:200'],
            'equipment.*.equipment_type_id' => ['nullable', 'integer'],
            'equipment.*.description' => ['nullable', 'string', 'max:150'],
            'equipment.*.working_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'equipment.*.idle_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'materials' => ['nullable', 'array', 'max:200'],
            'materials.*.material_id' => ['required', 'integer'],
            'materials.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'materials.*.unit_id' => ['nullable', 'integer'],
            'materials.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->dprs->update($dpr, $data);

        return new DprResource($this->detail($updated));
    }

    public function refresh(Project $project, Dpr $dpr): DprResource
    {
        $this->assertOnProject($project, $dpr);
        Gate::authorize('update', $dpr);
        $updated = $this->dprs->refresh($dpr);

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
            'qty' => fn ($v) => $v === null ? '—' : IndianNumber::quantity($v),
        ])->setPaper('a4');

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

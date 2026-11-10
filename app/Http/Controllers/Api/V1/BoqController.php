<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Boq\BoqStatus;
use App\Enums\Imports\ImportStatus;
use App\Http\Controllers\Boq\BoqPresenter;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoqResource;
use App\Models\Boq\Boq;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Services\Boq\BoqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BoqController extends Controller
{
    public function __construct(private readonly BoqService $boqs) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Boq::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BoqStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $boqs = $project->boqs()
            ->with('approver:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('boq_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('boq_number')
            ->orderByDesc('version')
            ->paginate($perPage);

        $boqs->getCollection()->each->setRelation('project', $project);

        return BoqResource::collection($boqs);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Boq::class, $project]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'drawing_revision_ids' => ['required', 'array', 'min:1'],
            'drawing_revision_ids.*' => ['integer', 'distinct', 'min:1'],
        ], [
            'drawing_revision_ids.required' => 'BOQ creation is restricted. At least one drawing must be approved before creating a BOQ for this project.',
            'drawing_revision_ids.min' => 'BOQ creation is restricted. At least one drawing must be approved before creating a BOQ for this project.',
        ]);
        $boq = $this->boqs->create($project, $data);

        return $this->detail($request, $project, $boq)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Project $project, Boq $boq): BoqResource
    {
        $this->assertBoq($project, $boq);
        Gate::authorize('view', $boq);

        return $this->detail($request, $project, $boq);
    }

    public function submit(Request $request, Project $project, Boq $boq): BoqResource
    {
        $this->assertBoq($project, $boq);
        Gate::authorize('submit', $boq);

        $this->boqs->submit($boq, $request->user());
        $boq->refresh()->setRelation('project', $project);

        return new BoqResource($boq->load('approver:id,name'));
    }

    public function eligibleDrawings(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [Boq::class, $project]);

        $canViewFiles = $request->user()->can('viewAny', [Drawing::class, $project]);

        return response()->json([
            'data' => $this->boqs->eligibleDrawingRevisions($project)
                ->map(fn (DrawingRevision $revision) => BoqPresenter::drawingRevision($revision, $project, $canViewFiles))
                ->values()
                ->all(),
        ]);
    }

    private function detail(Request $request, Project $project, Boq $boq): BoqResource
    {
        $withCosts = $request->user()->can('boq.view_costs');
        $boq->setRelation('project', $project);
        $boq->load(['approver:id,name', 'sourceImport.uploader:id,name']);
        $boq->load(['items' => function ($query) use ($withCosts) {
            $query->with(array_values(array_filter([
                'attachments.uploader:id,name',
                $withCosts ? 'simpleMaterialCosts' : null,
            ])))->orderBy('sort_order')->orderBy('id');
        }]);
        $boq->setRelation('drawingRevisions', $this->linkedDrawingRevisions($boq));

        return (new BoqResource($boq))
            ->detailed()
            ->withHistory($this->boqImportHistory($boq));
    }

    /**
     * @return Collection<int, DrawingRevision>
     */
    private function linkedDrawingRevisions(Boq $boq): Collection
    {
        return $boq->drawingRevisions()
            ->with([
                'drawing.currentRevision.decider:id,name',
                'drawing:id,company_id,project_id,drawing_number,title,current_revision_id,status',
                'decider:id,name',
            ])
            ->orderBy('drawing_revisions.id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boqImportHistory(Boq $boq): array
    {
        return DataImport::query()
            ->with('uploader:id,name')
            ->where('module', 'boq.items')
            ->where('context->boq_id', $boq->id)
            ->whereIn('status', [
                ImportStatus::Completed->value,
                ImportStatus::CompletedWithErrors->value,
                ImportStatus::Failed->value,
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (DataImport $import) => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'sheet' => $import->context['sheet_name'] ?? null,
                'uploaded_by' => $import->uploader?->only(['id', 'name']),
                'date' => ($import->finished_at ?? $import->created_at)?->toIso8601String(),
                'rows' => $import->create_count ?? $import->valid_rows,
                'status' => $import->status instanceof ImportStatus ? $import->status->value : $import->status,
                'status_label' => $import->status instanceof ImportStatus ? $import->status->label() : (string) $import->status,
                'download_url' => route('api.v1.imports.file', $import),
            ])
            ->all();
    }

    private function assertBoq(Project $project, Boq $boq): void
    {
        abort_unless((int) $boq->project_id === (int) $project->id, 404);
    }
}

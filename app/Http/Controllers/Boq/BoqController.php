<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Imports\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Boq\BoqItemsRequest;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Imports\DataImport;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\BoqRevisionDiffService;
use App\Services\Boq\BoqRevisionService;
use App\Services\Boq\BoqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BoqController extends Controller
{
    public function __construct(
        private readonly BoqService $boqs,
        private readonly BoqRevisionService $revisions,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Boq::class, $project]);

        $user = $request->user();
        $withCosts = $user->can('boq.view_costs');
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BoqStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $boqs = $project->boqs()
            ->with('approver:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('boq_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('boq_number')
            ->orderByDesc('version')
            ->limit(500)
            ->get()
            ->each->setRelation('project', $project);

        return Inertia::render('Boq/Index', [
            'project' => ProjectHeader::for($project),
            'boqs' => $boqs->map(fn (Boq $boq) => [
                ...BoqPresenter::header($boq, $withCosts),
                'can' => BoqPresenter::abilities($user, $boq),
            ])->all(),
            'filters' => $filters,
            'statuses' => BoqStatus::options(),
            'approved_drawings' => $this->drawingOptions($project, $this->boqs->eligibleDrawingRevisions($project), $user),
            'can' => [
                'create' => $user->can('create', [Boq::class, $project]),
                'view_costs' => $withCosts,
                'import' => $user->can('boq.import'),
                'view_drawings' => $user->can('viewAny', [Drawing::class, $project]),
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
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

        return redirect()->route('projects.boqs.show', [$project, $boq])->with('success', "BOQ {$boq->boq_number} created.");
    }

    public function show(Request $request, Project $project, Boq $boq): Response
    {
        Gate::authorize('view', $boq);

        $user = $request->user();
        $withCosts = $user->can('boq.view_costs');
        $boq->load(['approver:id,name', 'sourceImport.uploader:id,name']);

        $sections = $boq->sections()->orderBy('sort_order')->orderBy('id')->get();
        $items = $boq->items()
            ->with(array_values(array_filter([
                'attachments.uploader:id,name',
                $withCosts ? 'simpleMaterialCosts' : null,
            ])))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $analyses = $withCosts && $boq->isEditable()
            ? $this->boqs->applicableAnalyses($boq)->values()->map(
                fn (RateAnalysis $ra) => BoqPresenter::analysisOption($ra, $this->boqs->snapshotFromAnalysis($ra))
            )->all()
            : [];

        $pending = $boq->pendingApprovalRequest();
        $canViewDrawings = $user->can('viewAny', [Drawing::class, $project]);
        $linked = $this->linkedDrawingRevisions($boq);
        $drawings = $linked->map(fn (DrawingRevision $revision) => BoqPresenter::drawingRevision($revision, $project, $canViewDrawings))->all();

        return Inertia::render('Boq/Show', [
            'project' => ProjectHeader::for($project),
            'boq' => BoqPresenter::header($boq, $withCosts),
            'drawings' => $drawings,
            'approved_drawings' => $this->drawingOptions($project, $this->pickerRevisions($project, $linked), $user),
            'has_newer_approved_revision' => collect($drawings)->contains(fn (array $row) => $row['newer_revision'] !== null),
            'sections' => $sections->map(fn (BoqSection $s) => $s->only(['id', 'parent_id', 'code', 'name', 'discipline', 'sort_order']))->all(),
            'items' => $items->map(fn (BoqItem $i) => BoqPresenter::item($i, $withCosts))->all(),
            'units' => Unit::query()->active()->orderBy('symbol')->get(['id', 'name', 'symbol'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol, 'name' => $u->name])->all(),
            'analyses' => $analyses,
            'versions' => $this->versionHistory($project, $boq, $withCosts),
            'approval' => $pending ? [
                'id' => $pending->id,
                'level' => $pending->current_level,
                'levels' => count($pending->steps ?? []),
                'step_name' => $pending->currentStep()['name'] ?? null,
                'can_act' => $this->approvals->canAct($pending, $user),
                'can_cancel' => (int) $pending->submitted_by === (int) $user->id,
            ] : null,
            'can' => [...BoqPresenter::abilities($user, $boq), 'view_costs' => $withCosts, 'view_drawings' => $canViewDrawings],
            'source_import' => $boq->sourceImport ? [
                'id' => $boq->sourceImport->id,
                'original_filename' => $boq->sourceImport->original_filename,
                'created_at' => $boq->sourceImport->created_at?->toIso8601String(),
                'uploaded_by' => $boq->sourceImport->uploader?->only(['id', 'name']),
                'download_url' => route('imports.file', $boq->sourceImport),
                'sheet' => $boq->sourceImport->context['sheet_name'] ?? null,
            ] : null,
            'import_history' => $this->boqImportHistory($boq),
            'source_imports' => $this->boqSourceImports($boq),
        ]);
    }

    public function update(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('update', $boq);

        $this->boqs->update($boq, $request->validate(['title' => ['required', 'string', 'max:200']]));

        return back()->with('success', 'BOQ updated.');
    }

    public function syncDrawings(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('update', $boq);

        $data = $request->validate([
            'drawing_revision_ids' => ['required', 'array', 'min:1'],
            'drawing_revision_ids.*' => ['integer', 'distinct', 'min:1'],
        ], [
            'drawing_revision_ids.required' => 'Select at least one approved drawing revision.',
            'drawing_revision_ids.min' => 'Select at least one approved drawing revision.',
        ]);
        $this->boqs->syncDrawingRevisions($boq, $data['drawing_revision_ids']);

        return back()->with('success', 'Source drawings updated.');
    }

    public function destroy(Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('delete', $boq);

        $this->boqs->delete($boq);

        return redirect()->route('projects.boqs.index', $project)->with('success', "BOQ {$boq->boq_number} v{$boq->version} deleted.");
    }

    public function saveItems(BoqItemsRequest $request, Project $project, Boq $boq): RedirectResponse
    {
        $data = $request->validated();
        $this->boqs->syncItems($boq, $data['rows'], array_map('intval', $data['deleted_ids']), $request->user()->can('boq.view_costs'));

        return back()->with('success', 'BOQ saved.');
    }

    public function submit(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('submit', $boq);

        $this->boqs->submit($boq, $request->user());

        return back()->with('success', 'BOQ submitted for approval.');
    }

    public function revise(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('revise', $boq);

        $data = $request->validate([
            'revision_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $revision = $this->revisions->revise($boq, $data['revision_reason'], $request->user());
        $summary = $revision->advancedCloneSummary;
        $detail = is_array($summary)
            ? sprintf(
                ' Cloned: %d items, %d measurements, %d norms, %d cost rows, %d BBS, %d quotes, %d planning maps.',
                (int) ($summary['items'] ?? 0),
                (int) ($summary['measurements'] ?? 0),
                (int) ($summary['material_norms'] ?? 0),
                (int) ($summary['cost_components'] ?? 0),
                (int) ($summary['bbs'] ?? 0),
                (int) ($summary['rate_comparison_quotes'] ?? 0),
                (int) ($summary['planning_maps'] ?? 0),
            )
            : '';

        return redirect()->route('projects.boqs.show', [$project, $revision])
            ->with('success', "Revision v{$revision->version} created as a draft.{$detail}");
    }

    public function compare(Request $request, Project $project, Boq $boq): JsonResponse
    {
        Gate::authorize('view', $boq);

        $previousId = (int) $request->validate(['previous_id' => ['required', 'integer']])['previous_id'];
        $previous = Boq::query()
            ->where('project_id', $project->id)
            ->where('boq_number', $boq->boq_number)
            ->whereKey($previousId)
            ->firstOrFail();

        $withCosts = $request->user()->can('boq.view_costs');
        $diff = app(BoqRevisionDiffService::class)->compare($previous, $boq, $withCosts);

        return response()->json($diff);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versionHistory(Project $project, Boq $boq, bool $withCosts): array
    {
        $versions = Boq::query()
            ->with(['creator:id,name', 'approver:id,name', 'drawingRevisions.drawing:id,drawing_number,title'])
            ->where('project_id', $project->id)
            ->where('boq_number', $boq->boq_number)
            ->orderByDesc('version')
            ->get();

        return $versions->map(function (Boq $b) use ($withCosts) {
            $drawings = $b->drawingRevisions->map(fn (DrawingRevision $rev) => [
                'id' => $rev->id,
                'drawing_number' => $rev->drawing?->drawing_number,
                'title' => $rev->drawing?->title,
                'revision_code' => $rev->revision_code,
            ])->values()->all();

            return [
                'id' => $b->id,
                'version' => $b->version,
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'is_current' => $b->is_current,
                'revision_reason' => $b->revision_reason,
                'created_at' => $b->created_at?->toIso8601String(),
                'created_by' => $b->creator?->name,
                'approved_at' => $b->approved_at?->toIso8601String(),
                'approved_by' => $b->approver?->name,
                'total_client_amount' => (string) $b->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $b->total_cost_amount : null,
                'parent_boq_id' => $b->parent_boq_id,
                'drawings' => $drawings,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, DrawingRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    private function drawingOptions(Project $project, Collection $revisions, User $user): array
    {
        $canViewFiles = $user->can('viewAny', [Drawing::class, $project]);

        return $revisions
            ->map(fn (DrawingRevision $revision) => BoqPresenter::drawingRevision($revision, $project, $canViewFiles))
            ->all();
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
     * Eligible current revisions plus any historically linked (possibly superseded) rows so an
     * editor can keep the original source instead of being forced onto the latest approval.
     *
     * @param  Collection<int, DrawingRevision>  $linked
     * @return Collection<int, DrawingRevision>
     */
    private function pickerRevisions(Project $project, Collection $linked): Collection
    {
        $eligible = $this->boqs->eligibleDrawingRevisions($project)->keyBy('id');

        foreach ($linked as $revision) {
            $eligible->put($revision->id, $revision);
        }

        return $eligible->sortBy(fn (DrawingRevision $revision) => [
            $revision->drawing?->drawing_number,
            $revision->revision_code,
        ])->values();
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
                'download_url' => route('imports.file', $import),
            ])
            ->all();
    }

    /**
     * Successful source workbooks for this BOQ (all batches, not only the latest pointer).
     *
     * @return list<array<string, mixed>>
     */
    private function boqSourceImports(Boq $boq): array
    {
        return DataImport::query()
            ->with('uploader:id,name')
            ->where('module', 'boq.items')
            ->where('context->boq_id', $boq->id)
            ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::CompletedWithErrors->value])
            ->orderByDesc('id')
            ->get()
            ->map(fn (DataImport $import) => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'sheet' => $import->context['sheet_name'] ?? null,
                'created_at' => $import->created_at?->toIso8601String(),
                'uploaded_by' => $import->uploader?->only(['id', 'name']),
                'download_url' => route('imports.file', $import),
                'is_primary' => (int) $boq->source_import_id === (int) $import->id,
            ])
            ->all();
    }
}

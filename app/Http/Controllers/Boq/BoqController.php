<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Boq\BoqItemsRequest;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\BoqRevisionService;
use App\Services\Boq\BoqService;
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
        $boq->load('approver:id,name');

        $sections = $boq->sections()->orderBy('sort_order')->orderBy('id')->get();
        $items = $boq->items()->orderBy('sort_order')->orderBy('id')->get();

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
            'versions' => Boq::query()
                ->where('project_id', $project->id)
                ->where('boq_number', $boq->boq_number)
                ->orderByDesc('version')
                ->get(['id', 'version', 'status', 'is_current'])
                ->map(fn (Boq $b) => ['id' => $b->id, 'version' => $b->version, 'status' => $b->status->value, 'is_current' => $b->is_current])
                ->all(),
            'approval' => $pending ? [
                'id' => $pending->id,
                'level' => $pending->current_level,
                'levels' => count($pending->steps ?? []),
                'step_name' => $pending->currentStep()['name'] ?? null,
                'can_act' => $this->approvals->canAct($pending, $user),
                'can_cancel' => (int) $pending->submitted_by === (int) $user->id,
            ] : null,
            'can' => [...BoqPresenter::abilities($user, $boq), 'view_costs' => $withCosts, 'view_drawings' => $canViewDrawings],
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

    public function revise(Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('revise', $boq);

        $revision = $this->revisions->revise($boq);

        return redirect()->route('projects.boqs.show', [$project, $revision])
            ->with('success', "Revision v{$revision->version} created as a draft.");
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
}

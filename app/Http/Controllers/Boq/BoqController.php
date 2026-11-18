<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Imports\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\Boq\BoqItemsRequest;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Boq\BoqSection;
use App\Models\Boq\ConcreteMixProfile;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Imports\DataImport;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\AdvancedBoqRevisionComparisonService;
use App\Services\Boq\AdvancedBoqTechnicalApprovalValidator;
use App\Services\Boq\BoqBudgetMaterialSyncService;
use App\Services\Boq\BoqRevisionService;
use App\Services\Boq\BoqService;
use App\Services\Projects\ProjectCostHeadService;
use App\Support\Boq\AdvancedBoqApprovalStage;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Boq\BoqRevisionPairResolver;
use App\Support\Math\Decimal;
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
        $advanced = BoqCompanySettings::enabled();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BoqStatus::class)],
            'approval_stage' => ['nullable', 'string', Rule::in(array_column(AdvancedBoqApprovalStage::filterSelectOptions(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $boqs = $project->boqs()
            ->with(['approver:id,name', 'technicalApprover:id,name', 'latestApprovalRequest.actions'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('boq_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('boq_number')
            ->orderByDesc('version')
            ->limit(500)
            ->get()
            ->each->setRelation('project', $project);

        if ($advanced && ($filters['approval_stage'] ?? null)) {
            $stageFilter = AdvancedBoqApprovalStage::from($filters['approval_stage']);
            $boqs = $boqs->filter(fn (Boq $boq) => AdvancedBoqApprovalStage::for($boq) === $stageFilter)->values();
        }

        return Inertia::render('Boq/Index', [
            'project' => ProjectHeader::for($project),
            'boqs' => $boqs->map(fn (Boq $boq) => [
                ...BoqPresenter::header($boq, $withCosts),
                'can' => BoqPresenter::abilities($user, $boq),
            ])->all(),
            'filters' => $filters,
            'statuses' => BoqStatus::options(),
            'approval_stages' => $advanced ? AdvancedBoqApprovalStage::filterSelectOptions() : [],
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
        $advanced = BoqCompanySettings::enabled();
        $boq->load(['approver:id,name', 'technicalApprover:id,name', 'sourceImport.uploader:id,name', 'creator:id,name']);

        $sections = $boq->sections()->orderBy('sort_order')->orderBy('id')->get();
        $items = $boq->items()
            ->with(array_values(array_filter([
                'attachments.uploader:id,name',
                'projectCostHead:id,code,name',
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
        if ($pending) {
            $pending->load(['actions.user:id,name', 'submitter:id,name']);
        }
        $canViewDrawings = $user->can('viewAny', [Drawing::class, $project]);
        $linked = $this->linkedDrawingRevisions($boq);
        $drawings = $linked->map(fn (DrawingRevision $revision) => BoqPresenter::drawingRevision($revision, $project, $canViewDrawings))->all();

        $approvalStage = $advanced ? AdvancedBoqApprovalStage::for($boq, $pending) : null;
        $atCommercial = $advanced && $boq->isTechnicallyApproved() && $pending?->isLastLevel();

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
            'cost_heads' => collect(app(ProjectCostHeadService::class)->options($project->company))
                ->map(fn (array $h) => [
                    'value' => $h['id'],
                    'label' => $h['code'],
                    'description' => $h['name'],
                ])->values()->all(),
            'concrete_mix_profiles' => (! $advanced)
                ? ConcreteMixProfile::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'name', 'concrete_type', 'grade'])
                    ->map(fn ($p) => [
                        'value' => $p->id,
                        'label' => $p->name,
                        'concrete_type' => $p->concrete_type,
                        'grade' => $p->grade,
                    ])->all()
                : [],
            'analyses' => $analyses,
            'versions' => $this->versionHistory($project, $boq, $withCosts),
            'approval' => $pending ? [
                'id' => $pending->id,
                'level' => $pending->current_level,
                'levels' => count($pending->steps ?? []),
                'step_name' => $pending->currentStep()['name'] ?? null,
                'is_last_level' => $pending->isLastLevel(),
                'is_commercial_level' => (bool) $atCommercial,
                'can_act' => $this->approvals->canAct($pending, $user),
                'can_cancel' => (int) $pending->submitted_by === (int) $user->id,
                'submitted_by' => $pending->submitter?->name,
                'submitted_at' => $pending->submitted_at?->toIso8601String(),
                'expected_updated_at' => $boq->updated_at?->toIso8601String(),
            ] : null,
            'approval_stage' => $approvalStage?->value,
            'approval_stage_label' => $approvalStage?->label(),
            'approval_timeline' => $advanced ? $this->approvalTimeline($boq) : [],
            'technical_review' => $advanced ? [
                'status' => $approvalStage?->label(),
                'technical_approved_at' => $boq->technical_approved_at?->toIso8601String(),
                'technical_approved_by' => $boq->technicalApprover?->name,
                'blocking_issues' => $boq->isEditable()
                    ? app(AdvancedBoqTechnicalApprovalValidator::class)->errors($boq)
                    : [],
                'snapshot_hash' => $boq->technical_snapshot_hash,
                'internal_cost' => $withCosts
                    ? ($boq->technical_approval_snapshot['totals']['grand_internal'] ?? $boq->total_cost_amount)
                    : null,
            ] : null,
            'commercial_review' => $advanced && $withCosts ? [
                'status' => $approvalStage?->label(),
                'internal_cost' => $boq->technical_approval_snapshot['totals']['grand_internal'] ?? $boq->total_cost_amount,
                'commercial_value' => $boq->total_client_amount,
                'margin' => Decimal::of((string) $boq->total_client_amount)
                    ->minus((string) ($boq->technical_approval_snapshot['totals']['grand_internal'] ?? $boq->total_cost_amount))
                    ->toMoney(),
                'margin_pct' => Decimal::of((string) $boq->total_client_amount)->isZero()
                    ? null
                    : Decimal::of((string) $boq->total_client_amount)
                        ->minus((string) ($boq->technical_approval_snapshot['totals']['grand_internal'] ?? $boq->total_cost_amount))
                        ->dividedBy((string) $boq->total_client_amount, Decimal::PERCENT_SCALE)
                        ->times(100)
                        ->round(Decimal::PERCENT_SCALE)
                        ->toString(),
                'technical_approved_at' => $boq->technical_approved_at?->toIso8601String(),
            ] : null,
            'can' => [
                ...BoqPresenter::abilities($user, $boq),
                'view_costs' => $withCosts,
                'view_drawings' => $canViewDrawings,
                'create_change_order' => $user->can('create', [BoqRevisionChangeOrder::class, $project]),
            ],
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
            'budget_sync' => $withCosts && $advanced
                ? $this->budgetSyncCard($project, $boq)
                : null,
            'advanced_boq' => $advanced,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalTimeline(Boq $boq): array
    {
        $events = [];
        $events[] = [
            'at' => $boq->created_at?->toIso8601String(),
            'label' => 'Created',
            'by' => $boq->creator?->name,
            'remarks' => null,
        ];

        $requests = $boq->approvalRequests()->with(['actions.user:id,name', 'submitter:id,name'])->get();
        foreach ($requests as $request) {
            foreach ($request->actions as $action) {
                $events[] = [
                    'at' => $action->acted_at?->toIso8601String() ?? $action->created_at?->toIso8601String(),
                    'label' => ($request->stepForLevel((int) $action->level)['name'] ?? 'Level '.$action->level)
                        .' — '.$action->action->label(),
                    'by' => $action->user?->name,
                    'remarks' => $action->comments,
                ];
            }
        }

        if ($boq->technical_approved_at) {
            $events[] = [
                'at' => $boq->technical_approved_at->toIso8601String(),
                'label' => 'Technical Approved',
                'by' => $boq->technicalApprover?->name,
                'remarks' => null,
            ];
        }

        if ($boq->approved_at) {
            $events[] = [
                'at' => $boq->approved_at->toIso8601String(),
                'label' => 'Final Approved',
                'by' => $boq->approver?->name,
                'remarks' => null,
            ];
        }

        return collect($events)
            ->filter(fn (array $e) => filled($e['at']))
            ->sortBy('at')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetSyncCard(Project $project, Boq $boq): array
    {
        $sync = app(BoqBudgetMaterialSyncService::class);
        $proposed = $sync->proposedSyncPayload($boq);
        $status = $sync->syncStatus($project, $boq);
        $internal = $proposed['reconciliation']['grand'];
        $budgetGenerated = $status['variance'] !== null && $status['status'] !== 'never_synced'
            ? Decimal::of($internal)->plus((string) ($status['variance'] ?? '0'))->toMoney()
            : null;

        return [
            ...$status,
            'advanced_internal_cost' => $internal,
            'material_cost_a' => $proposed['reconciliation']['material'],
            'labour_cost_b' => $proposed['reconciliation']['labour'],
            'budget_generated_cost' => $budgetGenerated,
            'budget_index_url' => route('projects.budget.index', $project),
        ];
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

        [$from, $to] = app(BoqRevisionPairResolver::class)->resolve($project, $boq, $request);
        Gate::authorize('view', $from);

        $withCosts = $request->user()->can('boq.view_costs');
        $diff = app(AdvancedBoqRevisionComparisonService::class)->compare($from, $to, $withCosts);

        return response()->json($diff);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versionHistory(Project $project, Boq $boq, bool $withCosts): array
    {
        $versions = Boq::query()
            ->with(['creator:id,name', 'approver:id,name', 'technicalApprover:id,name', 'drawingRevisions.drawing:id,drawing_number,title'])
            ->where('project_id', $project->id)
            ->where('boq_number', $boq->boq_number)
            ->orderByDesc('version')
            ->get();

        /** @var array<int, string|null> $prevCommercial */
        $prevCommercial = [];
        $orderedAsc = $versions->sortBy('version')->values();
        foreach ($orderedAsc as $b) {
            $prevCommercial[(int) $b->id] = null;
            $prior = $orderedAsc->firstWhere('version', $b->version - 1);
            if ($prior !== null) {
                $prevCommercial[(int) $b->id] = (string) $prior->total_client_amount;
            }
        }

        return $versions->map(function (Boq $b) use ($withCosts, $prevCommercial) {
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
                'technical_approved_at' => $b->technical_approved_at?->toIso8601String(),
                'technical_approved_by' => $b->technicalApprover?->name,
                'total_client_amount' => (string) $b->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $b->total_cost_amount : null,
                'commercial_change_from_prev' => $prevCommercial[(int) $b->id] !== null
                    ? Decimal::of((string) $b->total_client_amount)->minus($prevCommercial[(int) $b->id])->toMoney()
                    : null,
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

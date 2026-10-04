<?php

namespace App\Http\Controllers\Quality;

use App\Enums\Quality\CheckpointResult;
use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Controllers\SiteExecution\SiteExecutionPresenter;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityInspection;
use App\Models\Quality\QualityInspectionItem;
use App\Models\User;
use App\Services\Quality\QualityInspectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QualityInspectionController extends Controller
{
    public function __construct(private readonly QualityInspectionService $inspections) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [QualityInspection::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InspectionStatus::class)],
            'result' => ['nullable', Rule::enum(InspectionResult::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->qualityInspections()
            ->with(['checklist:id,name', 'site:id,name', 'engineer:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('inspection_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('location', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Quality/Inspections/Index', [
            'project' => ProjectHeader::for($project),
            'inspections' => $page->through(fn (QualityInspection $i) => $this->header($i)),
            'filters' => $filters,
            'statuses' => InspectionStatus::options(),
            'results' => InspectionResult::options(),
            'can' => ['create' => $request->user()->can('create', [QualityInspection::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [QualityInspection::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [QualityInspection::class, $project]);

        $data = $request->validate(['quality_checklist_id' => ['required', 'integer'], ...$this->detailRules()], [], ['quality_checklist_id' => 'checklist']);
        $inspection = $this->inspections->create($project, $data, $request->user());

        return redirect()->route('projects.inspections.show', [$project, $inspection])->with('success', "Inspection {$inspection->inspection_number} requested.");
    }

    public function show(Request $request, Project $project, QualityInspection $qualityInspection): Response
    {
        Gate::authorize('view', $qualityInspection);
        $user = $request->user();
        $inspection = $qualityInspection->load(['checklist:id,name', 'site:id,name', 'task:id,wbs_code,name', 'boqItem:id,item_code,name', 'requester:id,name', 'engineer:id,name', 'completer:id,name']);
        $items = $inspection->items()->get();
        $counts = $items->countBy(fn (QualityInspectionItem $i) => $i->result?->value ?? 'pending');

        return Inertia::render('Quality/Inspections/Show', [
            'project' => ProjectHeader::for($project),
            'inspection' => [
                ...$this->header($inspection),
                'request_notes' => $inspection->request_notes,
                'remarks' => $inspection->remarks,
                'task' => $inspection->task ? trim($inspection->task->wbs_code.' '.$inspection->task->name) : null,
                'boq_item' => $inspection->boqItem ? trim(($inspection->boqItem->item_code ? $inspection->boqItem->item_code.' ' : '').$inspection->boqItem->name) : null,
                'requested_by' => $inspection->requester?->name,
                'completed_by' => $inspection->completer?->name,
                'completed_at' => $inspection->completed_at?->toIso8601String(),
                'engineer_id' => $inspection->engineer_id,
            ],
            'items' => $items->map(fn (QualityInspectionItem $i) => [
                'id' => $i->id,
                'sort_order' => $i->sort_order,
                'checkpoint' => $i->checkpoint,
                'acceptance_criteria' => $i->acceptance_criteria,
                'result' => $i->result?->value,
                'remark' => $i->remark,
            ])->all(),
            'counts' => [
                'pass' => $counts->get('pass', 0),
                'fail' => $counts->get('fail', 0),
                'na' => $counts->get('na', 0),
                'pending' => $counts->get('pending', 0),
            ],
            'ncrs' => $inspection->ncrs()->latest('id')->get()->map(fn (Ncr $n) => [
                'id' => $n->id,
                'ncr_number' => $n->ncr_number,
                'severity_label' => $n->severity->label(),
                'severity' => $n->severity->value,
                'status' => $n->status->value,
                'status_label' => $n->status->label(),
            ])->all(),
            'checkpointResults' => CheckpointResult::options(),
            'results' => InspectionResult::options(),
            'members' => SiteExecutionPresenter::memberOptions($project),
            'attachments' => ProcurementPresenter::attachments($inspection),
            'audit' => AuditPresenter::trail([$inspection]),
            'can' => $this->abilities($user, $inspection, $project),
            'today' => now()->toDateString(),
        ]);
    }

    public function edit(Project $project, QualityInspection $qualityInspection): Response
    {
        Gate::authorize('edit', $qualityInspection);

        return $this->form($project, $qualityInspection);
    }

    public function update(Request $request, Project $project, QualityInspection $qualityInspection): RedirectResponse
    {
        Gate::authorize('edit', $qualityInspection);

        $this->inspections->update($qualityInspection, $request->validate($this->detailRules()));

        return redirect()->route('projects.inspections.show', [$project, $qualityInspection])->with('success', 'Inspection updated.');
    }

    public function destroy(Project $project, QualityInspection $qualityInspection): RedirectResponse
    {
        Gate::authorize('delete', $qualityInspection);

        $this->inspections->delete($qualityInspection);

        return redirect()->route('projects.inspections.index', $project)->with('success', "Inspection {$qualityInspection->inspection_number} deleted.");
    }

    public function schedule(Request $request, Project $project, QualityInspection $qualityInspection): RedirectResponse
    {
        Gate::authorize('schedule', $qualityInspection);

        $data = $request->validate([
            'inspection_date' => ['required', 'date'],
            'engineer_id' => ['nullable', 'integer'],
        ]);
        $this->inspections->schedule($qualityInspection, $data);

        return back()->with('success', 'Inspection scheduled.');
    }

    public function record(Request $request, Project $project, QualityInspection $qualityInspection): RedirectResponse
    {
        Gate::authorize('perform', $qualityInspection);

        $this->inspections->record($qualityInspection, $request->validate($this->itemRules())['items']);

        return back()->with('success', 'Checkpoint results saved.');
    }

    public function complete(Request $request, Project $project, QualityInspection $qualityInspection): RedirectResponse
    {
        Gate::authorize('perform', $qualityInspection);

        $data = $request->validate([
            'result' => ['nullable', Rule::enum(InspectionResult::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(required: false),
        ]);
        $this->inspections->complete($qualityInspection, $data, $request->user());
        $result = $qualityInspection->result;

        return back()->with('success', "Inspection completed: {$result->label()}.".($result->needsFollowUp() ? ' Raise an NCR for the non-conformance if needed.' : ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRules(): array
    {
        return [
            'site_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'task_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'request_notes' => ['nullable', 'string', 'max:1000'],
            'inspection_date' => ['nullable', 'date'],
            'engineer_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(bool $required = true): array
    {
        return [
            'items' => [$required ? 'required' : 'nullable', 'array', 'max:200'],
            'items.*.result' => ['nullable', Rule::enum(CheckpointResult::class)],
            'items.*.remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function form(Project $project, ?QualityInspection $inspection): Response
    {
        return Inertia::render('Quality/Inspections/Form', [
            'project' => ProjectHeader::for($project),
            'inspection' => $inspection ? [
                'id' => $inspection->id,
                'inspection_number' => $inspection->inspection_number,
                'status' => $inspection->status->value,
                'checklist' => $inspection->checklist?->name,
                ...$inspection->only(['site_id', 'location', 'task_id', 'boq_item_id', 'request_notes', 'engineer_id']),
                'inspection_date' => $inspection->inspection_date?->toDateString(),
            ] : null,
            'checklists' => $inspection ? [] : QualityChecklist::query()->active()->withCount('items')->orderBy('name')->get()
                ->map(fn (QualityChecklist $c) => ['value' => $c->id, 'label' => $c->name, 'description' => $c->discipline->label().' · '.$c->items_count.' checkpoints'])->all(),
            'sites' => ProcurementPresenter::siteOptions($project),
            'tasks' => ProcurementPresenter::taskOptions($project),
            'boqItems' => ProcurementPresenter::boqItemOptions($project),
            'members' => SiteExecutionPresenter::memberOptions($project),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(QualityInspection $inspection): array
    {
        return [
            'id' => $inspection->id,
            'inspection_number' => $inspection->inspection_number,
            'status' => $inspection->status->value,
            'status_label' => $inspection->status->label(),
            'result' => $inspection->result?->value,
            'result_label' => $inspection->result?->label(),
            'checklist' => $inspection->checklist?->name,
            'site' => $inspection->site?->name,
            'location' => $inspection->location,
            'inspection_date' => $inspection->inspection_date?->toDateString(),
            'engineer' => $inspection->engineer?->name,
        ];
    }

    /**
     * Permission AND state (platform super admins pass every policy check).
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, QualityInspection $inspection, Project $project): array
    {
        $status = $inspection->status;

        return [
            'edit' => $status !== InspectionStatus::Completed && $user->can('edit', $inspection),
            'delete' => $status === InspectionStatus::Requested && $user->can('delete', $inspection),
            'schedule' => $status === InspectionStatus::Requested && $user->can('schedule', $inspection),
            'perform' => $status === InspectionStatus::Scheduled && $user->can('perform', $inspection),
            'attach' => $status !== InspectionStatus::Completed && $user->can('update', $inspection),
            'raiseNcr' => $status === InspectionStatus::Completed && (bool) $inspection->result?->needsFollowUp()
                && $user->can('create', [Ncr::class, $project]),
        ];
    }
}

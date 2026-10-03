<?php

namespace App\Http\Controllers\SiteExecution;

use App\Enums\SiteExecution\DprStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\SiteExecution\DprRequest;
use App\Models\Core\Company;
use App\Models\Masters\Unit;
use App\Models\Planning\ProgressEntry;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprEquipment;
use App\Models\SiteExecution\DprItem;
use App\Models\SiteExecution\DprLabour;
use App\Models\SiteExecution\DprMaterial;
use App\Models\SiteExecution\SiteDiary;
use App\Services\SiteExecution\DprAggregationService;
use App\Services\SiteExecution\DprService;
use App\Support\Format\IndianNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DprController extends Controller
{
    public function __construct(
        private readonly DprService $dprs,
        private readonly DprAggregationService $aggregation,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Dpr::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DprStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = Dpr::query()->where('project_id', $project->id)
            ->with('engineer:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('dpr_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('dpr_date')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SiteExecution/Dprs/Index', [
            'project' => ProjectHeader::for($project),
            'dprs' => $page->through(fn (Dpr $dpr) => [
                ...$this->header($dpr),
                'engineer' => $dpr->engineer?->name,
                'items_count' => $dpr->items_count,
            ]),
            'filters' => $filters,
            'statuses' => DprStatus::options(),
            'can' => ['create' => $request->user()->can('create', [Dpr::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [Dpr::class, $project]);

        $date = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today']])['date'] ?? now()->toDateString();
        $date = substr($date, 0, 10);
        $existing = Dpr::query()->where('project_id', $project->id)->whereDate('dpr_date', $date)->first(['id', 'dpr_number']);
        $preview = $this->aggregation->aggregate($project, $date);

        return Inertia::render('SiteExecution/Dprs/Create', [
            'project' => ProjectHeader::for($project),
            'date' => $date,
            'today' => now()->toDateString(),
            'existing' => $existing ? ['id' => $existing->id, 'dpr_number' => $existing->dpr_number] : null,
            'diaries' => $this->diaryRows($project, $date),
            'preview' => [
                'weather' => $preview['weather'],
                'site_issues' => $preview['site_issues'],
                'items' => $this->previewItems($preview['items']),
                'labour_count' => count($preview['labours']),
                'headcount' => array_sum(array_column($preview['labours'], 'headcount')),
                'equipment_count' => count($preview['equipment']),
                'material_count' => count($preview['materials']),
            ],
            'members' => SiteExecutionPresenter::memberOptions($project),
            'defaultEngineer' => $request->user()->isProjectMember($project) ? $request->user()->id : null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Dpr::class, $project]);

        $data = $request->validate([
            'dpr_date' => ['required', 'date', 'before_or_equal:today'],
            'engineer_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ], [], ['dpr_date' => 'DPR date', 'engineer_id' => 'engineer']);

        $dpr = $this->dprs->create($project, $data);

        return redirect()->route('projects.dprs.show', [$project, $dpr])->with('success', "{$dpr->dpr_number} created from the approved site diaries.");
    }

    public function show(Request $request, Project $project, Dpr $dpr): Response
    {
        Gate::authorize('view', $dpr);

        $user = $request->user();
        $dpr->load(['engineer:id,name', 'approver:id,name', 'reopener:id,name', 'creator:id,name']);
        $items = $dpr->items()->with(['task:id,wbs_code,name', 'boqItem:id,item_code,name', 'unit:id,symbol'])->get();

        return Inertia::render('SiteExecution/Dprs/Show', [
            'project' => ProjectHeader::for($project),
            'dpr' => [
                ...$this->header($dpr),
                ...$dpr->only(['weather', 'site_issues', 'remarks', 'revision', 'reopen_reason']),
                'engineer' => $dpr->engineer?->name,
                'created_by' => $dpr->creator?->name,
                'approved_by' => $dpr->approver?->name,
                'approved_at' => $dpr->approved_at?->toIso8601String(),
                'reopened_by' => $dpr->reopener?->name,
                'reopened_at' => $dpr->reopened_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (DprItem $i) => $this->itemRow($i))->all(),
            'labours' => $this->labourRows($dpr),
            'equipment' => $this->equipmentRows($dpr),
            'materials' => $this->materialRows($dpr),
            'diaries' => $this->diaryRows($project, $dpr->dpr_date->toDateString()),
            'entries' => ProgressEntry::query()
                ->where('source_type', (new DprItem)->getMorphClass())
                ->whereIn('source_id', $items->pluck('id'))
                ->with('task:id,wbs_code,name')
                ->orderBy('id')
                ->get()
                ->map(fn (ProgressEntry $e) => [
                    'id' => $e->id,
                    'task' => $e->task ? trim($e->task->wbs_code.' '.$e->task->name) : null,
                    'boq_line_uid' => $e->boq_line_uid,
                    'quantity' => $e->quantity,
                    'entry_date' => $e->entry_date?->toDateString(),
                    'posting_ref' => $e->posting_ref,
                    'is_reversal' => $e->reverses_id !== null,
                    'created_at' => $e->created_at?->toIso8601String(),
                ])->all(),
            'approval' => ProcurementPresenter::approval($dpr, $user),
            'attachments' => ProcurementPresenter::attachments($dpr),
            // State is checked here as well as in the policy: Gate::before grants super admins everything.
            'can' => [
                'update' => $dpr->isEditable() && $user->can('update', $dpr),
                'delete' => $dpr->isEditable() && $dpr->revision === 0 && $user->can('delete', $dpr),
                'submit' => $dpr->isEditable() && $user->can('submit', $dpr),
                'reopen' => $dpr->status === DprStatus::Approved && $user->can('reopen', $dpr),
                'export' => $user->can('export', $dpr),
                'attach' => $dpr->isEditable() && $user->can('update', $dpr),
            ],
        ]);
    }

    public function edit(Project $project, Dpr $dpr): Response
    {
        Gate::authorize('update', $dpr);
        abort_unless($dpr->isEditable(), 403);

        return Inertia::render('SiteExecution/Dprs/Edit', [
            'project' => ProjectHeader::for($project),
            'dpr' => [
                ...$this->header($dpr),
                ...$dpr->only(['engineer_id', 'weather', 'site_issues', 'remarks']),
                'items' => $dpr->items()->get()->map(fn (DprItem $i) => [
                    ...$i->only(['id', 'task_id', 'description', 'unit_id', 'executed_qty', 'planned_qty', 'cumulative_qty', 'balance_qty']),
                    'boq_item_id' => SiteExecutionPresenter::currentBoqItemId($project, $i->boq_item_id),
                ])->all(),
                'labours' => $dpr->labours()->get()->map(fn (DprLabour $l) => $l->only(['labour_trade_id', 'subcontractor_id', 'headcount', 'hours', 'remarks']))->all(),
                'equipment' => $dpr->equipment()->get()->map(fn (DprEquipment $e) => $e->only(['equipment_type_id', 'description', 'working_hours', 'idle_hours']))->all(),
                'materials' => $dpr->materials()->get()->map(fn (DprMaterial $m) => $m->only(['material_id', 'quantity', 'unit_id', 'remarks']))->all(),
            ],
            'options' => [...SiteExecutionPresenter::options($project), 'members' => SiteExecutionPresenter::memberOptions($project)],
        ]);
    }

    public function update(DprRequest $request, Project $project, Dpr $dpr): RedirectResponse
    {
        $this->dprs->update($dpr, $request->validated());

        return redirect()->route('projects.dprs.show', [$project, $dpr])->with('success', 'DPR updated.');
    }

    public function refresh(Project $project, Dpr $dpr): RedirectResponse
    {
        Gate::authorize('update', $dpr);
        $this->dprs->refresh($dpr);

        return back()->with('success', 'DPR refreshed from the approved site diaries.');
    }

    public function destroy(Project $project, Dpr $dpr): RedirectResponse
    {
        Gate::authorize('delete', $dpr);
        $this->dprs->delete($dpr);

        return redirect()->route('projects.dprs.index', $project)->with('success', "{$dpr->dpr_number} deleted.");
    }

    public function submit(Request $request, Project $project, Dpr $dpr): RedirectResponse
    {
        Gate::authorize('submit', $dpr);
        $this->dprs->submit($dpr, $request->user());

        return back()->with('success', 'DPR submitted for approval.');
    }

    public function reopen(Request $request, Project $project, Dpr $dpr): RedirectResponse
    {
        Gate::authorize('reopen', $dpr);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->dprs->reopen($dpr, $request->user(), $reason);

        return back()->with('success', 'DPR reopened: its progress was reversed and it is a draft again.');
    }

    /**
     * Printable DPR from stored values only (snapshots frozen at approval).
     */
    public function pdf(Project $project, Dpr $dpr): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('export', $dpr);

        $dpr->load(['engineer:id,name', 'approver:id,name']);

        return Pdf::loadView('pdf.dpr', [
            'dpr' => $dpr,
            'company' => Company::query()->findOrFail($dpr->company_id),
            'project' => $project,
            'items' => $dpr->items()->with(['task:id,wbs_code,name', 'boqItem:id,item_code,name', 'unit:id,symbol'])->get(),
            'labours' => $dpr->labours()->with(['trade:id,name', 'subcontractor:id,name'])->get(),
            'equipment' => $dpr->equipment()->with('equipmentType:id,name')->get(),
            'materials' => $dpr->materials()->with(['material:id,code,name', 'unit:id,symbol'])->get(),
            'qty' => fn ($v) => $v === null ? '—' : IndianNumber::quantity($v),
        ])->setPaper('a4')->download("{$dpr->dpr_number}.pdf");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function previewItems(array $items): array
    {
        $units = Unit::query()->withTrashed()->whereKey(array_column($items, 'unit_id'))->pluck('symbol', 'id');

        return array_map(fn (array $i) => [
            'description' => $i['description'],
            'executed_qty' => $i['executed_qty'],
            'unit' => $units[$i['unit_id']] ?? null,
            'linked' => $i['task_id'] !== null || $i['boq_item_id'] !== null,
        ], $items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function diaryRows(Project $project, string $date): array
    {
        return SiteDiary::query()->where('project_id', $project->id)->whereDate('diary_date', $date)
            ->with(['site:id,name', 'creator:id,name'])->withCount('workItems')->orderBy('id')->get()
            ->map(fn (SiteDiary $d) => [
                'id' => $d->id,
                'site' => $d->site?->name ?? $d->work_location,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'created_by' => $d->creator?->name,
                'work_items_count' => $d->work_items_count,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(DprItem $i): array
    {
        return [
            'id' => $i->id,
            'task' => $i->task ? trim($i->task->wbs_code.' '.$i->task->name) : null,
            'boq_item' => $i->boqItem ? trim($i->boqItem->item_code.' '.$i->boqItem->name) : null,
            'description' => $i->description,
            'unit' => $i->unit?->symbol,
            'planned_qty' => $i->planned_qty,
            'executed_qty' => $i->executed_qty,
            'cumulative_qty' => $i->cumulative_qty,
            'balance_qty' => $i->balance_qty,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function labourRows(Dpr $dpr): array
    {
        return $dpr->labours()->with(['trade:id,name', 'subcontractor:id,name'])->get()
            ->map(fn (DprLabour $l) => ['id' => $l->id, 'trade' => $l->trade?->name, 'subcontractor' => $l->subcontractor?->name, 'headcount' => $l->headcount, 'hours' => $l->hours, 'remarks' => $l->remarks])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function equipmentRows(Dpr $dpr): array
    {
        return $dpr->equipment()->with('equipmentType:id,name')->get()
            ->map(fn (DprEquipment $e) => ['id' => $e->id, 'type' => $e->equipmentType?->name, 'description' => $e->description, 'working_hours' => $e->working_hours, 'idle_hours' => $e->idle_hours])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialRows(Dpr $dpr): array
    {
        return $dpr->materials()->with(['material:id,code,name', 'unit:id,symbol'])->get()
            ->map(fn (DprMaterial $m) => ['id' => $m->id, 'material' => $m->material?->name, 'code' => $m->material?->code, 'quantity' => $m->quantity, 'unit' => $m->unit?->symbol, 'remarks' => $m->remarks])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Dpr $dpr): array
    {
        return [
            'id' => $dpr->id,
            'dpr_number' => $dpr->dpr_number,
            'dpr_date' => $dpr->dpr_date?->toDateString(),
            'status' => $dpr->status->value,
            'status_label' => $dpr->status->label(),
        ];
    }
}

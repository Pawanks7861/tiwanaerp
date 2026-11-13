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
use App\Models\SiteExecution\DprConstraint;
use App\Models\SiteExecution\DprEquipment;
use App\Models\SiteExecution\DprItem;
use App\Models\SiteExecution\DprLabour;
use App\Models\SiteExecution\DprMaterial;
use App\Models\SiteExecution\SiteDiary;
use App\Services\SiteExecution\DprPrefillService;
use App\Services\SiteExecution\DprService;
use App\Support\Format\IndianNumber;
use App\Support\Pdf\PdfResponder;
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
        private readonly DprPrefillService $prefill,
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

        $validated = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'include_completed' => ['nullable', 'boolean'],
        ]);
        $date = substr($validated['date'] ?? now()->toDateString(), 0, 10);
        $includeCompleted = $request->boolean('include_completed');
        $existing = Dpr::query()->where('project_id', $project->id)->whereDate('dpr_date', $date)->first(['id', 'dpr_number']);
        $content = $this->prefill->forDateMergingLegacy($project, $date, $includeCompleted);

        return Inertia::render('SiteExecution/Dprs/Create', [
            'project' => ProjectHeader::for($project),
            'date' => $date,
            'today' => now()->toDateString(),
            'include_completed' => $includeCompleted,
            'existing' => $existing ? ['id' => $existing->id, 'dpr_number' => $existing->dpr_number] : null,
            'prefill' => $this->prefillSummary($content),
            'legacy_diaries' => $this->diaryRows($project, $date),
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
            'include_completed' => ['nullable', 'boolean'],
        ], [], ['dpr_date' => 'DPR date', 'engineer_id' => 'engineer']);

        $dpr = $this->dprs->create($project, $data);

        return redirect()->route('projects.dprs.show', [$project, $dpr])->with('success', "{$dpr->dpr_number} created.");
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
                ...$dpr->only([
                    'weather', 'site_issues', 'remarks', 'revision', 'reopen_reason',
                    'work_performed', 'constraints_summary', 'department', 'agency_name',
                ]),
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
            'constraints' => $this->constraintRows($dpr),
            'diaries' => $dpr->isLegacyDiarySourced() ? $this->diaryRows($project, $dpr->dpr_date->toDateString()) : [],
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
                ...$dpr->only([
                    'engineer_id', 'site_id', 'weather', 'site_issues', 'remarks',
                    'work_performed', 'constraints_summary', 'department', 'agency_name',
                ]),
                'items' => $dpr->items()->get()->map(fn (DprItem $i) => [
                    ...$i->only([
                        'id', 'task_id', 'description', 'unit_id', 'work_area', 'activity_name_snapshot',
                        'planned_start_date', 'planned_end_date', 'planned_qty', 'previous_executed_qty',
                        'today_target_qty', 'executed_qty', 'hold_quantity', 'hold_reason', 'hold_since',
                        'hold_responsible', 'hold_expected_resolution', 'hold_status', 'on_hold',
                        'cumulative_qty', 'balance_qty', 'target_variance', 'achievement_percent',
                        'completion_percent', 'shortfall_reason', 'remarks',
                    ]),
                    'boq_item_id' => SiteExecutionPresenter::currentBoqItemId($project, $i->boq_item_id),
                    'planned_start_date' => $i->planned_start_date?->toDateString(),
                    'planned_end_date' => $i->planned_end_date?->toDateString(),
                    'hold_since' => $i->hold_since?->toDateString(),
                    'hold_expected_resolution' => $i->hold_expected_resolution?->toDateString(),
                ])->all(),
                'labours' => $dpr->labours()->get()->map(fn (DprLabour $l) => $l->only([
                    'labour_trade_id', 'subcontractor_id', 'headcount', 'skilled', 'unskilled',
                    'operator_count', 'helper_count', 'hours', 'remarks',
                ]))->all(),
                'equipment' => $dpr->equipment()->get()->map(fn (DprEquipment $e) => $e->only([
                    'equipment_type_id', 'description', 'operator_name', 'start_time', 'end_time',
                    'duration_hours', 'working_hours', 'idle_hours', 'status', 'remarks',
                ]))->all(),
                'materials' => $dpr->materials()->get()->map(fn (DprMaterial $m) => $m->only([
                    'material_id', 'opening_qty', 'received_qty', 'issued_qty', 'closing_qty',
                    'quantity', 'unit_id', 'remarks',
                ]))->all(),
                'constraints' => $dpr->constraints()->get()->map(fn (DprConstraint $c) => [
                    ...$c->only([
                        'category', 'description', 'work_area', 'task_id', 'responsible', 'priority',
                        'status', 'remarks',
                    ]),
                    'raised_date' => $c->raised_date?->toDateString(),
                    'expected_resolution' => $c->expected_resolution?->toDateString(),
                ])->all(),
            ],
            'options' => [...SiteExecutionPresenter::options($project), 'members' => SiteExecutionPresenter::memberOptions($project)],
        ]);
    }

    public function update(DprRequest $request, Project $project, Dpr $dpr): RedirectResponse
    {
        $this->dprs->update($dpr, $request->validated());

        return redirect()->route('projects.dprs.show', [$project, $dpr])->with('success', 'DPR updated.');
    }

    public function refresh(Request $request, Project $project, Dpr $dpr): RedirectResponse
    {
        Gate::authorize('update', $dpr);
        $this->dprs->refresh($dpr, $request->boolean('include_completed'));

        return back()->with('success', 'DPR refreshed from planning and site summaries.');
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
    public function pdf(Request $request, Project $project, Dpr $dpr): \Symfony\Component\HttpFoundation\Response
    {
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

        return PdfResponder::send($pdf, "{$dpr->dpr_number}.pdf", $request->routeIs('projects.dprs.pdf.view'));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function prefillSummary(array $content): array
    {
        $items = $this->previewItems($content['items'] ?? []);

        return [
            'source' => $content['source'] ?? 'direct',
            'items' => $items,
            'labour_count' => count($content['labours'] ?? []),
            'headcount' => array_sum(array_column($content['labours'] ?? [], 'headcount')),
            'equipment_count' => count($content['equipment'] ?? []),
            'material_count' => count($content['materials'] ?? []),
            'constraints_count' => count($content['constraints'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function previewItems(array $items): array
    {
        $units = Unit::query()->withTrashed()->whereKey(array_column($items, 'unit_id'))->pluck('symbol', 'id');

        return array_map(fn (array $i) => [
            'work_area' => $i['work_area'] ?? null,
            'description' => $i['description'] ?? $i['activity_name_snapshot'] ?? null,
            'planned_start_date' => $i['planned_start_date'] ?? null,
            'planned_end_date' => $i['planned_end_date'] ?? null,
            'planned_qty' => $i['planned_qty'] ?? null,
            'previous_executed_qty' => $i['previous_executed_qty'] ?? null,
            'today_target_qty' => $i['today_target_qty'] ?? null,
            'executed_qty' => $i['executed_qty'] ?? '0',
            'hold_quantity' => $i['hold_quantity'] ?? '0',
            'unit' => $units[$i['unit_id'] ?? null] ?? ($i['unit_snapshot'] ?? null),
            'linked' => ($i['task_id'] ?? null) !== null || ($i['boq_item_id'] ?? null) !== null,
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
            'work_area' => $i->work_area,
            'task' => $i->task ? trim($i->task->wbs_code.' '.$i->task->name) : null,
            'boq_item' => $i->boqItem ? trim($i->boqItem->item_code.' '.$i->boqItem->name) : null,
            'description' => $i->description,
            'activity_name' => $i->activity_name_snapshot ?? $i->description,
            'unit' => $i->unit?->symbol ?? $i->unit_snapshot,
            'planned_start_date' => $i->planned_start_date?->toDateString(),
            'planned_end_date' => $i->planned_end_date?->toDateString(),
            'planned_qty' => $i->planned_qty,
            'previous_executed_qty' => $i->previous_executed_qty,
            'today_target_qty' => $i->today_target_qty,
            'executed_qty' => $i->executed_qty,
            'hold_quantity' => $i->hold_quantity,
            'on_hold' => $i->on_hold,
            'hold_reason' => $i->hold_reason,
            'cumulative_qty' => $i->cumulative_qty,
            'balance_qty' => $i->balance_qty,
            'target_variance' => $i->target_variance,
            'achievement_percent' => $i->achievement_percent,
            'completion_percent' => $i->completion_percent,
            'shortfall_reason' => $i->shortfall_reason,
            'remarks' => $i->remarks,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function labourRows(Dpr $dpr): array
    {
        return $dpr->labours()->with(['trade:id,name', 'subcontractor:id,name'])->get()
            ->map(fn (DprLabour $l) => [
                'id' => $l->id,
                'trade' => $l->trade?->name,
                'subcontractor' => $l->subcontractor?->name,
                'headcount' => $l->headcount,
                'skilled' => $l->skilled,
                'unskilled' => $l->unskilled,
                'operator_count' => $l->operator_count,
                'helper_count' => $l->helper_count,
                'hours' => $l->hours,
                'remarks' => $l->remarks,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function equipmentRows(Dpr $dpr): array
    {
        return $dpr->equipment()->with('equipmentType:id,name')->get()
            ->map(fn (DprEquipment $e) => [
                'id' => $e->id,
                'type' => $e->equipmentType?->name,
                'description' => $e->description,
                'operator_name' => $e->operator_name,
                'start_time' => $e->start_time,
                'end_time' => $e->end_time,
                'duration_hours' => $e->duration_hours,
                'working_hours' => $e->working_hours,
                'idle_hours' => $e->idle_hours,
                'status' => $e->status,
                'remarks' => $e->remarks,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialRows(Dpr $dpr): array
    {
        return $dpr->materials()->with(['material:id,code,name', 'unit:id,symbol'])->get()
            ->map(fn (DprMaterial $m) => [
                'id' => $m->id,
                'material' => $m->material?->name,
                'code' => $m->material?->code,
                'opening_qty' => $m->opening_qty,
                'received_qty' => $m->received_qty,
                'issued_qty' => $m->issued_qty,
                'closing_qty' => $m->closing_qty,
                'quantity' => $m->quantity,
                'unit' => $m->unit?->symbol,
                'remarks' => $m->remarks,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function constraintRows(Dpr $dpr): array
    {
        return $dpr->constraints()->with('task:id,wbs_code,name')->get()
            ->map(fn (DprConstraint $c) => [
                'id' => $c->id,
                'category' => $c->category,
                'description' => $c->description,
                'work_area' => $c->work_area,
                'task' => $c->task ? trim($c->task->wbs_code.' '.$c->task->name) : null,
                'responsible' => $c->responsible,
                'priority' => $c->priority,
                'raised_date' => $c->raised_date?->toDateString(),
                'expected_resolution' => $c->expected_resolution?->toDateString(),
                'status' => $c->status,
                'remarks' => $c->remarks,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Dpr $dpr): array
    {
        $source = $dpr->source ?? 'direct';

        return [
            'id' => $dpr->id,
            'dpr_number' => $dpr->dpr_number,
            'dpr_date' => $dpr->dpr_date?->toDateString(),
            'status' => $dpr->status->value,
            'status_label' => $dpr->status->label(),
            'source' => $source,
            'source_label' => $source === 'legacy_site_diary' ? 'Site diary (legacy)' : 'Direct entry',
        ];
    }
}

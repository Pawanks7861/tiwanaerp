<?php

namespace App\Http\Controllers\SiteExecution;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Requests\SiteExecution\SiteDiaryRequest;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryEquipment;
use App\Models\SiteExecution\SiteDiaryLabour;
use App\Models\SiteExecution\SiteDiaryMaterial;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Models\SiteExecution\SiteDiaryWorkItem;
use App\Models\User;
use App\Services\SiteExecution\SiteDiaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SiteDiaryController extends Controller
{
    public function __construct(private readonly SiteDiaryService $diaries) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [SiteDiary::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SiteDiaryStatus::class)],
            'date' => ['nullable', 'date'],
            'mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();

        $page = SiteDiary::query()->where('project_id', $project->id)
            ->with(['site:id,name', 'creator:id,name'])
            ->withCount(['workItems', 'photos'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('diary_date', $date))
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('created_by', $user->id))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('work_performed', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('work_location', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('diary_date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SiteExecution/Diaries/Index', [
            'project' => ProjectHeader::for($project),
            'diaries' => $page->through(fn (SiteDiary $d) => [
                ...$this->header($d),
                'site' => $d->site?->name,
                'work_location' => $d->work_location,
                'created_by' => $d->creator?->name,
                'work_items_count' => $d->work_items_count,
                'photos_count' => $d->photos_count,
            ]),
            'filters' => $filters,
            'statuses' => SiteDiaryStatus::options(),
            'can' => ['create' => $user->can('create', [SiteDiary::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [SiteDiary::class, $project]);

        return $this->form($project, null);
    }

    public function store(SiteDiaryRequest $request, Project $project): RedirectResponse
    {
        $diary = $this->diaries->create($project, $request->validated());

        if ($request->input('intent') === 'photos') {
            return redirect()->route('projects.site-diaries.edit', [$project, $diary, 'step' => 'photos'])->with('success', 'Draft saved. Add the photos now.');
        }

        return $this->afterSave($request, $project, $diary, 'Site diary saved as draft.');
    }

    /**
     * Save-and-submit from the form. The draft is already stored, so a submit failure returns to the
     * edit form with the reason instead of losing the entry.
     */
    private function afterSave(Request $request, Project $project, SiteDiary $diary, string $saved): RedirectResponse
    {
        if ($request->input('intent') === 'submit' && $request->user()->can('submit', $diary->refresh())) {
            try {
                $this->diaries->submit($diary, $request->user());
            } catch (ValidationException $e) {
                return redirect()->route('projects.site-diaries.edit', [$project, $diary])->withErrors($e->errors())->with('error', $saved.' It could not be submitted yet.');
            }

            return redirect()->route('projects.site-diaries.show', [$project, $diary])->with('success', 'Site diary saved and submitted for review.');
        }

        return redirect()->route('projects.site-diaries.show', [$project, $diary])->with('success', $saved);
    }

    public function show(Request $request, Project $project, SiteDiary $siteDiary): Response
    {
        Gate::authorize('view', $siteDiary);

        $user = $request->user();
        $diary = $siteDiary->load(['site:id,name', 'creator:id,name', 'submitter:id,name', 'reviewer:id,name', 'approver:id,name', 'rejecter:id,name']);

        return Inertia::render('SiteExecution/Diaries/Show', [
            'project' => ProjectHeader::for($project),
            'diary' => [
                ...$this->header($diary),
                ...$diary->only(['uuid', 'weather', 'temperature', 'work_location', 'work_performed', 'issues', 'safety_incidents', 'remarks', 'latitude', 'longitude', 'rejection_reason']),
                'site' => $diary->site?->name,
                'captured_at' => $diary->captured_at?->toIso8601String(),
                'created_by' => $diary->creator?->name,
                'submitted_by' => $diary->submitter?->name,
                'submitted_at' => $diary->submitted_at?->toIso8601String(),
                'reviewed_by' => $diary->reviewer?->name,
                'reviewed_at' => $diary->reviewed_at?->toIso8601String(),
                'approved_by' => $diary->approver?->name,
                'approved_at' => $diary->approved_at?->toIso8601String(),
                'rejected_by' => $diary->rejecter?->name,
                'rejected_at' => $diary->rejected_at?->toIso8601String(),
            ],
            'work_items' => $diary->workItems()->with(['task:id,wbs_code,name,planned_qty,completed_qty', 'boqItem:id,item_code,name', 'subcontractor:id,name', 'unit:id,symbol'])->get()
                ->map(fn (SiteDiaryWorkItem $w) => [
                    'id' => $w->id,
                    'task' => $w->task ? trim($w->task->wbs_code.' '.$w->task->name) : null,
                    'boq_item' => $w->boqItem ? trim($w->boqItem->item_code.' '.$w->boqItem->name) : null,
                    'subcontractor' => $w->subcontractor?->name,
                    'description' => $w->description,
                    'quantity' => $w->quantity,
                    'unit' => $w->unit?->symbol,
                ])->all(),
            'labours' => $diary->labours()->with(['trade:id,name', 'subcontractor:id,name'])->get()
                ->map(fn (SiteDiaryLabour $l) => [
                    'id' => $l->id, 'trade' => $l->trade?->name, 'subcontractor' => $l->subcontractor?->name,
                    'headcount' => $l->headcount, 'hours' => $l->hours, 'remarks' => $l->remarks,
                ])->all(),
            'equipment' => $diary->equipment()->with('equipmentType:id,name')->get()
                ->map(fn (SiteDiaryEquipment $e) => [
                    'id' => $e->id, 'type' => $e->equipmentType?->name, 'description' => $e->description,
                    'working_hours' => $e->working_hours, 'idle_hours' => $e->idle_hours,
                ])->all(),
            'materials' => $diary->materials()->with(['material:id,code,name', 'unit:id,symbol'])->get()
                ->map(fn (SiteDiaryMaterial $m) => [
                    'id' => $m->id, 'material' => $m->material?->name, 'code' => $m->material?->code,
                    'quantity' => $m->quantity, 'unit' => $m->unit?->symbol, 'remarks' => $m->remarks,
                ])->all(),
            'photos' => $this->photos($project, $diary),
            'can' => $this->abilities($user, $diary),
        ]);
    }

    public function edit(Project $project, SiteDiary $siteDiary): Response
    {
        Gate::authorize('update', $siteDiary);
        abort_unless($siteDiary->isEditable(), 403);

        return $this->form($project, $siteDiary);
    }

    public function update(SiteDiaryRequest $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        $this->diaries->update($siteDiary, $request->validated());

        return $this->afterSave($request, $project, $siteDiary, 'Site diary updated.');
    }

    public function destroy(Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('delete', $siteDiary);

        $this->diaries->delete($siteDiary);

        return redirect()->route('projects.site-diaries.index', $project)->with('success', 'Site diary deleted.');
    }

    public function submit(Request $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('submit', $siteDiary);
        $this->diaries->submit($siteDiary, $request->user());

        return back()->with('success', 'Site diary submitted for review.');
    }

    public function review(Request $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('review', $siteDiary);
        $this->diaries->review($siteDiary, $request->user());

        return back()->with('success', 'Site diary marked as reviewed.');
    }

    public function approve(Request $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('approve', $siteDiary);
        $this->diaries->approve($siteDiary, $request->user());

        return back()->with('success', 'Site diary approved. It is now available for the DPR of that date.');
    }

    public function reject(Request $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('reject', $siteDiary);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->diaries->reject($siteDiary, $request->user(), $reason);

        return back()->with('success', 'Site diary rejected and returned to its author.');
    }

    private function form(Project $project, ?SiteDiary $diary): Response
    {
        return Inertia::render('SiteExecution/Diaries/Form', [
            'project' => ProjectHeader::for($project),
            'diary' => $diary ? [
                'id' => $diary->id,
                ...$diary->only(['uuid', 'site_id', 'weather', 'temperature', 'work_location', 'work_performed', 'issues', 'safety_incidents', 'remarks', 'latitude', 'longitude']),
                'diary_date' => $diary->diary_date?->toDateString(),
                'captured_at' => $diary->captured_at?->toIso8601String(),
                'status' => $diary->status->value,
                'work_items' => $diary->workItems()->get()->map(fn (SiteDiaryWorkItem $w) => [
                    ...$w->only(['task_id', 'subcontractor_id', 'description', 'quantity', 'unit_id']),
                    'boq_item_id' => SiteExecutionPresenter::currentBoqItemId($project, $w->boq_item_id),
                ])->all(),
                'labours' => $diary->labours()->get()->map(fn (SiteDiaryLabour $l) => $l->only(['labour_trade_id', 'subcontractor_id', 'headcount', 'hours', 'remarks']))->all(),
                'equipment' => $diary->equipment()->get()->map(fn (SiteDiaryEquipment $e) => $e->only(['equipment_type_id', 'description', 'working_hours', 'idle_hours']))->all(),
                'materials' => $diary->materials()->get()->map(fn (SiteDiaryMaterial $m) => $m->only(['material_id', 'quantity', 'unit_id', 'remarks']))->all(),
                'photos' => $this->photos($project, $diary),
            ] : null,
            'options' => SiteExecutionPresenter::options($project),
            'weathers' => ['Sunny', 'Cloudy', 'Rain', 'Heavy rain', 'Windy', 'Hot', 'Cold', 'Fog'],
            'today' => now()->toDateString(),
            'photoMaxKb' => (int) config('uploads.photo_max_kb'),
            'canSubmit' => $diary ? request()->user()->can('submit', $diary) : request()->user()->can('site_diary.submit'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function photos(Project $project, SiteDiary $diary): array
    {
        return $diary->photos()->get()->map(fn (SiteDiaryPhoto $p) => [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'caption' => $p->caption,
            'taken_at' => $p->taken_at?->toIso8601String(),
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'size_bytes' => $p->size_bytes,
            'url' => route('projects.site-diaries.photos.show', [$project->id, $diary->id, $p->id]),
            'thumb_url' => route('projects.site-diaries.photos.thumb', [$project->id, $diary->id, $p->id]),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(SiteDiary $diary): array
    {
        return [
            'id' => $diary->id,
            'diary_date' => $diary->diary_date?->toDateString(),
            'status' => $diary->status->value,
            'status_label' => $diary->status->label(),
        ];
    }

    /**
     * UI abilities. State is checked here as well as in the policy, because Gate::before grants
     * platform super admins every ability regardless of the diary state.
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, SiteDiary $diary): array
    {
        $editable = $diary->isEditable();
        $notSubmitter = (int) ($diary->submitted_by ?? $diary->created_by) !== (int) $user->id;

        return [
            'update' => $editable && $user->can('update', $diary),
            'delete' => $editable && $user->can('delete', $diary),
            'submit' => $editable && $user->can('submit', $diary),
            'review' => $diary->status === SiteDiaryStatus::Submitted && $notSubmitter && $user->can('review', $diary),
            'approve' => $diary->status === SiteDiaryStatus::Reviewed && $notSubmitter && $user->can('approve', $diary),
            'reject' => in_array($diary->status, [SiteDiaryStatus::Submitted, SiteDiaryStatus::Reviewed], true) && $notSubmitter && $user->can('reject', $diary),
        ];
    }
}

<?php

namespace App\Http\Controllers\Scrap;

use App\Enums\Scrap\ScrapAdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapAdjustment;
use App\Models\Scrap\ScrapAdjustmentItem;
use App\Models\User;
use App\Reports\ReportRunner;
use App\Services\Scrap\ScrapAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScrapAdjustmentController extends Controller
{
    public function __construct(
        private readonly ScrapAdjustmentService $adjustments,
        private readonly ReportRunner $runner,
    ) {}

    public function companyIndex(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', ScrapAdjustment::class);

        return $this->list($request, null);
    }

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ScrapAdjustment::class, $project]);

        return $this->list($request, $project);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [ScrapAdjustment::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [ScrapAdjustment::class, $project]);

        $adjustment = $this->adjustments->create($project, $this->validated($request));

        return redirect()->route('projects.scrap.adjustments.show', [$project, $adjustment])
            ->with('success', "Adjustment {$adjustment->adjustment_number} saved as draft.");
    }

    public function show(Request $request, Project $project, ScrapAdjustment $scrapAdjustment): Response
    {
        abort_unless((int) $scrapAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('view', $scrapAdjustment);

        $user = $request->user();
        $adjustment = $scrapAdjustment->load(['approver:id,name', 'creator:id,name']);

        return Inertia::render('Scrap/Adjustments/Show', [
            'project' => ProjectHeader::for($project),
            'adjustment' => [
                ...$this->header($adjustment),
                ...$adjustment->only(['direction', 'reason', 'remarks']),
                'created_by' => $adjustment->creator?->name,
                'approved_by' => $adjustment->approver?->name,
                'approved_at' => $adjustment->approved_at?->toIso8601String(),
            ],
            'items' => $adjustment->items()->with(['scrapType:id,name,code', 'unit:id,symbol'])->get()->map(fn (ScrapAdjustmentItem $i) => [
                'scrap_type' => $i->scrapType?->name,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->symbol,
                'remarks' => $i->remarks,
            ])->all(),
            'approval' => ProcurementPresenter::approval($adjustment, $user),
            'attachments' => ProcurementPresenter::attachments($adjustment),
            'can' => [...$this->abilities($user, $adjustment), 'attach' => $adjustment->isEditable() && $user->can('update', $adjustment)],
        ]);
    }

    public function edit(Project $project, ScrapAdjustment $scrapAdjustment): Response
    {
        abort_unless((int) $scrapAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('update', $scrapAdjustment);

        return $this->form($project, $scrapAdjustment);
    }

    public function update(Request $request, Project $project, ScrapAdjustment $scrapAdjustment): RedirectResponse
    {
        abort_unless((int) $scrapAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('update', $scrapAdjustment);

        $this->adjustments->update($scrapAdjustment, $this->validated($request));

        return redirect()->route('projects.scrap.adjustments.show', [$project, $scrapAdjustment])->with('success', 'Adjustment updated.');
    }

    public function destroy(Project $project, ScrapAdjustment $scrapAdjustment): RedirectResponse
    {
        abort_unless((int) $scrapAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $scrapAdjustment);

        $this->adjustments->delete($scrapAdjustment);

        return redirect()->route('projects.scrap.adjustments.index', $project)->with('success', "Adjustment {$scrapAdjustment->adjustment_number} deleted.");
    }

    public function submit(Request $request, Project $project, ScrapAdjustment $scrapAdjustment): RedirectResponse
    {
        abort_unless((int) $scrapAdjustment->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $scrapAdjustment);

        $this->adjustments->submit($scrapAdjustment, $request->user());

        return back()->with('success', 'Adjustment submitted for approval.');
    }

    private function list(Request $request, ?Project $project): Response
    {
        $user = $request->user();
        $visible = $project ? [(int) $project->id] : $this->runner->visibleProjectIds($user);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ScrapAdjustmentStatus::class)],
            'project' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = ScrapAdjustment::query()
            ->with(['project:id,code,name'])
            ->withCount('items')
            ->whereIn('project_id', $visible)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->when($filters['project'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('adjustment_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Scrap/Adjustments/Index', [
            'scope' => $project ? 'project' : 'company',
            'project' => $project ? ProjectHeader::for($project) : null,
            'adjustments' => $page->through(fn (ScrapAdjustment $a) => [
                ...$this->header($a),
                'project' => $a->project?->only(['id', 'code', 'name']),
                'direction' => $a->direction,
                'items_count' => $a->items_count,
                'show_url' => route('projects.scrap.adjustments.show', [$a->project_id, $a->id]),
            ]),
            'filters' => $filters,
            'statuses' => ScrapAdjustmentStatus::options(),
            'projects' => $project ? [] : ScrapPresenter::projectFilterOptions($visible),
            'can' => ['create' => $project && $user->can('create', [ScrapAdjustment::class, $project])],
        ]);
    }

    private function form(Project $project, ?ScrapAdjustment $adjustment): Response
    {
        return Inertia::render('Scrap/Adjustments/Form', [
            'project' => ProjectHeader::for($project),
            'adjustment' => $adjustment ? [
                'id' => $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                ...$adjustment->only(['direction', 'reason', 'remarks']),
                'items' => $adjustment->items()->get()->map(fn (ScrapAdjustmentItem $i) => [
                    'scrap_type_id' => $i->scrap_type_id,
                    'quantity' => $i->quantity,
                    'unit_id' => $i->unit_id,
                    'remarks' => $i->remarks,
                ])->all(),
            ] : null,
            'options' => [
                'categories' => ScrapPresenter::categoryOptions(),
                'scrapTypes' => ScrapPresenter::typeOptions(),
            ],
            'balances' => ScrapPresenter::balanceMap($project),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.scrap_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(ScrapAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'adjustment_number' => $adjustment->adjustment_number,
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'status' => $adjustment->status->value,
            'status_label' => $adjustment->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, ScrapAdjustment $adjustment): array
    {
        $editable = $adjustment->isEditable();
        $submitted = $adjustment->status === ScrapAdjustmentStatus::Submitted;

        return [
            'update' => $editable && $user->can('update', $adjustment),
            'delete' => $editable && $user->can('delete', $adjustment),
            'submit' => $editable && $user->can('submit', $adjustment),
            'approve' => $submitted && $user->can('approve', $adjustment),
        ];
    }
}

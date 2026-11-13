<?php

namespace App\Http\Controllers\Scrap;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Enums\Scrap\ScrapSourceType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapGenerationItem;
use App\Models\User;
use App\Reports\ReportRunner;
use App\Rules\ExistsInCompany;
use App\Services\Scrap\ScrapGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScrapGenerationController extends Controller
{
    public function __construct(
        private readonly ScrapGenerationService $generations,
        private readonly ReportRunner $runner,
    ) {}

    public function companyIndex(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', ScrapGeneration::class);

        $visible = $this->runner->visibleProjectIds($request->user());
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ScrapGenerationStatus::class)],
            'project' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = ScrapGeneration::query()
            ->with(['project:id,code,name'])
            ->withCount('items')
            ->whereIn('project_id', $visible)
            ->when($filters['project'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('generation_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Scrap/Generations/Index', [
            'scope' => 'company',
            'project' => null,
            'generations' => $page->through(fn (ScrapGeneration $g) => [
                ...$this->header($g),
                'project' => $g->project?->only(['id', 'code', 'name']),
                'items_count' => $g->items_count,
                'show_url' => route('projects.scrap.generations.show', [$g->project_id, $g->id]),
            ]),
            'filters' => $filters,
            'statuses' => ScrapGenerationStatus::options(),
            'projects' => ScrapPresenter::projectFilterOptions($visible),
            'can' => ['create' => false],
        ]);
    }

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ScrapGenerationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = ScrapGeneration::query()
            ->where('project_id', $project->id)
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('generation_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Scrap/Generations/Index', [
            'scope' => 'project',
            'project' => ProjectHeader::for($project),
            'generations' => $page->through(fn (ScrapGeneration $g) => [
                ...$this->header($g),
                'items_count' => $g->items_count,
            ]),
            'filters' => $filters,
            'statuses' => ScrapGenerationStatus::options(),
            'projects' => [],
            'can' => ['create' => $user->can('create', [ScrapGeneration::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [ScrapGeneration::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [ScrapGeneration::class, $project]);

        $generation = $this->generations->create($project, $this->validated($request));

        return redirect()->route('projects.scrap.generations.show', [$project, $generation])
            ->with('success', "Scrap generation {$generation->generation_number} saved as draft.");
    }

    public function show(Request $request, Project $project, ScrapGeneration $scrapGeneration): Response
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('view', $scrapGeneration);

        $user = $request->user();
        $generation = $scrapGeneration->load([
            'department:id,name', 'submitter:id,name', 'approver:id,name', 'canceller:id,name', 'creator:id,name',
        ]);
        $items = $generation->items()->with(['scrapType:id,name,code,scrap_category_id', 'scrapType.category:id,name', 'unit:id,symbol'])->get();

        return Inertia::render('Scrap/Generations/Show', [
            'project' => ProjectHeader::for($project),
            'generation' => [
                ...$this->header($generation),
                ...$generation->only(['location', 'source_reference_type', 'source_reference_id', 'remarks', 'cancellation_reason']),
                'source_type' => $generation->source_type->value,
                'source_type_label' => $generation->source_type->label(),
                'department' => $generation->department?->name,
                'created_by' => $generation->creator?->name,
                'submitted_by' => $generation->submitter?->name,
                'approved_by' => $generation->approver?->name,
                'approved_at' => $generation->approved_at?->toIso8601String(),
                'cancelled_by' => $generation->canceller?->name,
                'cancelled_at' => $generation->cancelled_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (ScrapGenerationItem $i) => [
                'id' => $i->id,
                'scrap_type_id' => $i->scrap_type_id,
                'scrap_type' => $i->scrapType?->name,
                'category' => $i->scrapType?->category?->name,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->symbol,
                'weight' => $i->weight,
                'condition' => $i->condition,
                'remarks' => $i->remarks,
            ])->all(),
            'approval' => ProcurementPresenter::approval($generation, $user),
            'attachments' => ProcurementPresenter::attachments($generation),
            'can' => [...$this->abilities($user, $generation), 'attach' => $generation->isEditable() && $user->can('update', $generation)],
        ]);
    }

    public function edit(Project $project, ScrapGeneration $scrapGeneration): Response
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('update', $scrapGeneration);

        return $this->form($project, $scrapGeneration);
    }

    public function update(Request $request, Project $project, ScrapGeneration $scrapGeneration): RedirectResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('update', $scrapGeneration);

        $this->generations->update($scrapGeneration, $this->validated($request));

        return redirect()->route('projects.scrap.generations.show', [$project, $scrapGeneration])->with('success', 'Generation updated.');
    }

    public function destroy(Project $project, ScrapGeneration $scrapGeneration): RedirectResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $scrapGeneration);

        $this->generations->delete($scrapGeneration);

        return redirect()->route('projects.scrap.generations.index', $project)->with('success', "Generation {$scrapGeneration->generation_number} deleted.");
    }

    public function submit(Request $request, Project $project, ScrapGeneration $scrapGeneration): RedirectResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $scrapGeneration);

        $this->generations->submit($scrapGeneration, $request->user());

        return back()->with('success', 'Generation submitted for approval.');
    }

    public function approve(Request $request, Project $project, ScrapGeneration $scrapGeneration): RedirectResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('approve', $scrapGeneration);

        $this->generations->approve($scrapGeneration, $request->user());

        return back()->with('success', 'Generation approved and posted to scrap stock.');
    }

    public function cancel(Request $request, Project $project, ScrapGeneration $scrapGeneration): RedirectResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $scrapGeneration);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->generations->cancel($scrapGeneration, $request->user(), $reason);

        return back()->with('success', 'Generation cancelled.');
    }

    private function form(Project $project, ?ScrapGeneration $generation): Response
    {
        return Inertia::render('Scrap/Generations/Form', [
            'project' => ProjectHeader::for($project),
            'generation' => $generation ? [
                'id' => $generation->id,
                'generation_number' => $generation->generation_number,
                ...$generation->only(['location', 'department_id', 'remarks', 'source_reference_type', 'source_reference_id']),
                'generation_date' => $generation->generation_date?->toDateString(),
                'source_type' => $generation->source_type->value,
                'items' => $generation->items()->get()->map(fn (ScrapGenerationItem $i) => [
                    'scrap_type_id' => $i->scrap_type_id,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit_id' => $i->unit_id,
                    'weight' => $i->weight,
                    'condition' => $i->condition,
                    'remarks' => $i->remarks,
                ])->all(),
            ] : null,
            'options' => [
                'sourceTypes' => ScrapSourceType::options(),
                'categories' => ScrapPresenter::categoryOptions(),
                'scrapTypes' => ScrapPresenter::typeOptions(),
                'departments' => ScrapPresenter::departmentOptions(),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'generation_date' => ['required', 'date', 'before_or_equal:today'],
            'location' => ['nullable', 'string', 'max:150'],
            'source_type' => ['required', Rule::enum(ScrapSourceType::class)],
            'source_reference_type' => ['nullable', 'string', 'max:40'],
            'source_reference_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', new ExistsInCompany(\App\Models\Masters\Department::class, activeOnly: true)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.scrap_type_id' => ['required', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.weight' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.condition' => ['nullable', 'string', 'max:30'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(ScrapGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'generation_number' => $generation->generation_number,
            'generation_date' => $generation->generation_date?->toDateString(),
            'status' => $generation->status->value,
            'status_label' => $generation->status->label(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, ScrapGeneration $generation): array
    {
        $editable = $generation->isEditable();
        $submitted = $generation->status === ScrapGenerationStatus::Submitted;

        return [
            'update' => $editable && $user->can('update', $generation),
            'delete' => $editable && $user->can('delete', $generation),
            'submit' => $editable && $user->can('submit', $generation),
            'approve' => $submitted && $user->can('approve', $generation),
            'cancel' => $user->can('cancel', $generation),
        ];
    }
}

<?php

namespace App\Http\Controllers\Projects;

use App\Enums\IndianState;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectRequest;
use App\Http\Requests\Projects\PurgeProjectRequest;
use App\Models\Crm\Client;
use App\Models\Projects\Project;
use App\Services\Core\CompanyDirectory;
use App\Services\Projects\ProjectCostHeadService;
use App\Services\Projects\ProjectPurgeService;
use App\Services\Projects\ProjectService;
use App\Services\Reports\DashboardService;
use App\Support\Crm\DefaultClientResolver;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly CompanyDirectory $directory,
        private readonly DashboardService $dashboards,
        private readonly ProjectCostHeadService $costHeads,
        private readonly ProjectPurgeService $purge,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => ProjectStatus::tryFrom((string) $request->query('status'))?->value ?? 'all',
        ];

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['client:id,company_name', 'projectManager:id,name'])
            ->withCount(['sites', 'members' => fn ($q) => $q->where('is_active', true)])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term)
                    ->orWhere('project_number', 'like', $term)->orWhere('city', 'like', $term));
            })
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Project $p) => $this->summary($p));

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $filters,
            'statuses' => ProjectStatus::options(),
            'can' => [
                'create' => $request->user()->can('create', Project::class),
                'import' => $request->user()->can('projects.import'),
                'purge' => $request->user()->can('projects.purge'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        $canManageBudget = request()->user()->can('budget.update');

        return Inertia::render('Projects/Form', [
            'project' => null,
            'canManageBudget' => $canManageBudget,
            'costHeads' => $canManageBudget
                ? $this->costHeads->options(app(CurrentCompany::class)->require())
                : [],
        ] + $this->formOptions());
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $canManageBudget = $request->user()->can('budget.update');
        $project = $this->projects->create(
            $request->projectData(),
            $canManageBudget ? $request->initialBudgetByHeadId() : null,
            $canManageBudget,
        );

        return redirect()->route('projects.show', $project)->with('success', "Project {$project->code} created.");
    }

    public function show(Request $request, Project $project): Response
    {
        $project->load(['client:id,code,company_name', 'projectManager:id,name,email'])
            ->loadCount(['sites', 'members' => fn ($q) => $q->where('is_active', true)]);

        $user = $request->user();
        $financials = $user->can('dashboard.view_financials');
        $detail = $this->detail($project);
        if (! $financials) {
            $detail['contract_value'] = null;
        }

        return Inertia::render('Projects/Show', [
            'project' => $detail,
            'transitions' => array_map(
                fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                $project->status->allowedTransitions(),
            ),
            'dashboard' => $user->can('dashboard.view')
                ? $this->dashboards->project($project, $user, ReportPeriod::today(app(CurrentCompany::class)->require()))
                : null,
            'can' => [
                'update' => $user->can('update', $project),
                'changeStatus' => $user->can('changeStatus', $project),
                'viewFinancials' => $financials,
                'reports' => $user->can('reports.view'),
                'purge' => $user->can('purge', $project) && $project->status === ProjectStatus::Cancelled,
            ],
            'purge_preview' => ($user->can('purge', $project) && $project->status === ProjectStatus::Cancelled)
                ? $this->purge->preview($project)
                : null,
        ]);
    }

    public function purgePreview(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('purge', $project);

        return response()->json($this->purge->preview($project));
    }

    public function purge(PurgeProjectRequest $request, Project $project): RedirectResponse
    {
        $result = $this->purge->purge(
            $project,
            $request->user(),
            (string) $request->validated('confirmation_code'),
            $request->boolean('acknowledge'),
        );

        $message = 'Project and all project-owned data were permanently deleted.';
        if ($result['file_failures'] !== []) {
            $message = 'Project database data was deleted, but some stored files could not be removed. See purge report.';
        }

        return redirect()->route('projects.index')->with('success', $message);
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('Projects/Form', ['project' => $this->detail($project)] + $this->formOptions());
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->projects->update($project, $request->projectData());

        return redirect()->route('projects.show', $project)->with('success', 'Project updated.');
    }

    public function updateStatus(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $validated = $request->validate(['status' => ['required', Rule::enum(ProjectStatus::class)]]);
        $this->projects->changeStatus($project, ProjectStatus::from($validated['status']));

        return back()->with('success', "Project status changed to {$project->status->label()}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $defaultClient = app(DefaultClientResolver::class)->payload();

        return [
            'clients' => Client::query()->active()->orderBy('company_name')->get(['id', 'code', 'company_name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => "{$c->company_name} ({$c->code})"])->all(),
            'defaultClient' => $defaultClient,
            'managers' => $this->directory->memberOptions(),
            'states' => IndianState::options(),
            'projectTypes' => [
                ['value' => 'residential', 'label' => 'Residential'],
                ['value' => 'commercial', 'label' => 'Commercial'],
                ['value' => 'industrial', 'label' => 'Industrial'],
                ['value' => 'infrastructure', 'label' => 'Infrastructure'],
                ['value' => 'interior', 'label' => 'Interior / Fit-out'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_number' => $project->project_number,
            'code' => $project->code,
            'name' => $project->name,
            'city' => $project->city,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'client_name' => $project->client?->company_name,
            'manager_name' => $project->projectManager?->name,
            'start_date' => $project->start_date?->toDateString(),
            'expected_end_date' => $project->expected_end_date?->toDateString(),
            'contract_value' => $project->contract_value,
            'sites_count' => $project->sites_count,
            'members_count' => $project->members_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Project $project): array
    {
        $workflowCategories = $project->settings()->where('key', 'workflow_categories')->value('value');

        return [
            ...$project->only([
                'id', 'project_number', 'code', 'name', 'description', 'project_type', 'address', 'city',
                'state_code', 'latitude', 'longitude', 'client_id', 'project_manager_id', 'contract_value',
            ]),
            'workflow_categories' => is_array($workflowCategories) ? $workflowCategories : null,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'start_date' => $project->start_date?->toDateString(),
            'expected_end_date' => $project->expected_end_date?->toDateString(),
            'actual_end_date' => $project->actual_end_date?->toDateString(),
            'state_name' => $project->state_code ? IndianState::tryFrom($project->state_code)?->label() : null,
            'client' => $project->relationLoaded('client') ? $project->client?->only(['id', 'code', 'company_name']) : null,
            'manager' => $project->relationLoaded('projectManager') ? $project->projectManager?->only(['id', 'name']) : null,
            'sites_count' => $project->sites_count ?? null,
            'members_count' => $project->members_count ?? null,
        ];
    }
}

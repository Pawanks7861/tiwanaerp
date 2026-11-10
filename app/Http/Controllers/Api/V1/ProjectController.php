<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Boq\BoqStatus;
use App\Enums\Documents\DrawingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Projects\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $search = trim((string) $request->query('search', ''));
        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['client:id,code,company_name,contact_person,mobile', 'projectManager:id,name'])
            ->withCount($this->setupCounts())
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($w) use ($term) {
                    $w->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('project_number', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(ProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->projectData());

        return (new ProjectResource($this->withSetup($project)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($this->withSetup($project->load('sites')));
    }

    public function setupStatus(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $project->loadCount($this->setupCounts());

        $drawings = (int) ($project->drawings_count ?? 0);
        $approvedDrawings = (int) ($project->approved_drawings_count ?? 0);
        $boqs = (int) ($project->boqs_count ?? 0);
        $approvedBoqs = (int) ($project->approved_boqs_count ?? 0);

        return response()->json([
            'data' => [
                'project_id' => $project->id,
                'project_created' => true,
                'drawings_count' => $drawings,
                'has_drawing' => $drawings > 0,
                'has_approved_drawing' => $approvedDrawings > 0,
                'boqs_count' => $boqs,
                'has_boq' => $boqs > 0,
                'has_approved_boq' => $approvedBoqs > 0,
                'approved_drawings_count' => $approvedDrawings,
                'approved_boqs_count' => $approvedBoqs,
            ],
        ]);
    }

    private function withSetup(Project $project): Project
    {
        return $project
            ->load(['client:id,code,company_name,contact_person,mobile', 'projectManager:id,name'])
            ->loadCount($this->setupCounts());
    }

    /**
     * @return array<int|string, mixed>
     */
    private function setupCounts(): array
    {
        return [
            'drawings',
            'drawings as approved_drawings_count' => fn ($query) => $query->where('status', DrawingStatus::Approved),
            'boqs',
            'boqs as approved_boqs_count' => fn ($query) => $query->where('status', BoqStatus::Approved),
        ];
    }
}

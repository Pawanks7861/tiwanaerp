<?php

namespace App\Http\Controllers\Api\V1\Scrap;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Enums\Scrap\ScrapSourceType;
use App\Http\Controllers\Api\V1\Scrap\Concerns\MapsScrapGeneration;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScrapGenerationResource;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Rules\ExistsInCompany;
use App\Services\Scrap\ScrapGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScrapGenerationApiController extends Controller
{
    use MapsScrapGeneration;

    public function __construct(private readonly ScrapGenerationService $generations) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ScrapGenerationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = ScrapGeneration::query()
            ->where('project_id', $project->id)
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('generation_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return ScrapGenerationResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [ScrapGeneration::class, $project]);
        $generation = $this->generations->create($project, $this->validated($request));

        return (new ScrapGenerationResource($this->detail($generation)))->response()->setStatusCode(201);
    }

    public function show(Project $project, ScrapGeneration $scrapGeneration): ScrapGenerationResource
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('view', $scrapGeneration);

        return new ScrapGenerationResource($this->detail($scrapGeneration));
    }

    public function update(Request $request, Project $project, ScrapGeneration $scrapGeneration): ScrapGenerationResource
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('update', $scrapGeneration);

        $generation = $this->generations->update($scrapGeneration, $this->validated($request));

        return new ScrapGenerationResource($this->detail($generation));
    }

    public function destroy(Project $project, ScrapGeneration $scrapGeneration): JsonResponse
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $scrapGeneration);
        $this->generations->delete($scrapGeneration);

        return response()->json(['message' => 'Deleted.']);
    }

    public function submit(Request $request, Project $project, ScrapGeneration $scrapGeneration): ScrapGenerationResource
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $scrapGeneration);
        $this->generations->submit($scrapGeneration, $request->user());

        return new ScrapGenerationResource($this->detail($scrapGeneration->fresh()));
    }

    public function approve(Request $request, Project $project, ScrapGeneration $scrapGeneration): ScrapGenerationResource
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('approve', $scrapGeneration);
        $this->generations->approve($scrapGeneration, $request->user(), $request->input('comments'));

        return new ScrapGenerationResource($this->detail($scrapGeneration->fresh()));
    }

    public function cancel(Request $request, Project $project, ScrapGeneration $scrapGeneration): ScrapGenerationResource
    {
        abort_unless((int) $scrapGeneration->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $scrapGeneration);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->generations->cancel($scrapGeneration, $request->user(), $reason);

        return new ScrapGenerationResource($this->detail($scrapGeneration->fresh()));
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
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.weight' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.condition' => ['nullable', 'string', 'max:30'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }
}

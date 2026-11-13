<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Site\StoreSiteDiaryRequest;
use App\Http\Requests\Api\Site\UpdateSiteDiaryRequest;
use App\Http\Resources\SiteDiaryResource;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Services\SiteExecution\SiteDiaryPhotoService;
use App\Services\SiteExecution\SiteDiaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteDiaryController extends Controller
{
    public function __construct(
        private readonly SiteDiaryPhotoService $photos,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [SiteDiary::class, $project]);

        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(SiteDiaryStatus::class)],
            'created_by' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $diaries = SiteDiary::query()
            ->where('project_id', $project->id)
            ->with(['site:id,name', 'creator:id,name'])
            ->withCount(['workItems', 'photos'])
            ->when(! empty($filters['date']), fn ($q) => $q->whereDate('diary_date', $filters['date']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('diary_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('diary_date', '<=', $filters['to']))
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('site_id', (int) $filters['site_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['created_by']), fn ($q) => $q->where('created_by', (int) $filters['created_by']))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('work_performed', 'like', $term)
                    ->orWhere('work_location', 'like', $term)
                    ->orWhere('uuid', 'like', $term)
                    ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term)));
            })
            ->orderByDesc('diary_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return SiteDiaryResource::collection($diaries);
    }

    public function show(Project $project, SiteDiary $siteDiary): SiteDiaryResource
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return new SiteDiaryResource($this->withLines($siteDiary));
    }

    public function store(StoreSiteDiaryRequest $request, Project $project): JsonResponse
    {
        $data = $request->payload();
        $existing = SiteDiary::query()
            ->where('project_id', $project->id)
            ->where('uuid', $data['uuid'])
            ->first();

        if ($existing !== null) {
            return (new SiteDiaryResource($this->withLines($existing)))->response();
        }

        return $this->retiredGone();
    }

    public function update(UpdateSiteDiaryRequest $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function submit(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function review(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function approve(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function reject(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function storePhoto(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    public function photo(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): StreamedResponse
    {
        $this->assertOnProject($project, $siteDiary);
        abort_unless((int) $photo->site_diary_id === (int) $siteDiary->id, 404);
        Gate::authorize('view', $siteDiary);

        return $this->photos->stream($photo);
    }

    public function destroyPhoto(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        abort_unless((int) $photo->site_diary_id === (int) $siteDiary->id, 404);
        Gate::authorize('view', $siteDiary);

        return $this->retiredGone();
    }

    private function withLines(SiteDiary $diary): SiteDiary
    {
        return $diary->load([
            'site:id,name,address,is_active',
            'creator:id,name',
            'workItems.task:id,wbs_code,name',
            'workItems.boqItem:id,item_code,name,line_uid',
            'workItems.unit:id,symbol',
            'workItems.subcontractor:id,code,name',
            'labours.trade:id,name',
            'labours.subcontractor:id,code,name',
            'equipment.equipmentType:id,name',
            'materials.material:id,code,name',
            'materials.unit:id,symbol',
            'photos',
        ])->loadCount(['workItems', 'photos']);
    }

    private function assertOnProject(Project $project, SiteDiary $diary): void
    {
        abort_unless((int) $diary->project_id === (int) $project->id, 404);
    }

    private function retiredGone(): JsonResponse
    {
        return response()->json(['message' => SiteDiaryService::RETIRED_MESSAGE], 410);
    }
}

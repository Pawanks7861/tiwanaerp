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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteDiaryController extends Controller
{
    public function __construct(
        private readonly SiteDiaryService $diaries,
        private readonly SiteDiaryPhotoService $photos,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [SiteDiary::class, $project]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $diaries = SiteDiary::query()
            ->where('project_id', $project->id)
            ->with('site:id,name')
            ->orderByDesc('diary_date')
            ->orderByDesc('id')
            ->paginate($perPage);

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
            $this->storePhotos($request, $existing);

            return (new SiteDiaryResource($this->withLines($existing)))->response();
        }

        $diary = $this->diaries->create($project, $data);
        $this->storePhotos($request, $diary);

        if ($request->hasFile('photos') && $diary->fresh()?->isEditable()) {
            Gate::authorize('submit', $diary);
            $this->diaries->submit($diary, $request->user());
        }

        return (new SiteDiaryResource($this->withLines($diary->fresh())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSiteDiaryRequest $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);

        if ($siteDiary->status === SiteDiaryStatus::Approved) {
            throw ValidationException::withMessages(['diary' => 'An approved site diary cannot be edited.']);
        }

        $diary = $this->diaries->update($siteDiary, $request->payload());
        $this->storePhotos($request, $diary);

        return (new SiteDiaryResource($this->withLines($diary->fresh())))->response();
    }

    public function photo(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): StreamedResponse
    {
        $this->assertOnProject($project, $siteDiary);
        abort_unless((int) $photo->site_diary_id === (int) $siteDiary->id, 404);
        Gate::authorize('view', $siteDiary);

        return $this->photos->stream($photo);
    }

    private function storePhotos(Request $request, SiteDiary $diary): void
    {
        if (! $request->hasFile('photos') || ! $diary->isEditable()) {
            return;
        }

        $files = $request->file('photos');
        $files = is_array($files) ? $files : [$files];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $this->photos->store($diary, $file);
            }
        }
    }

    private function withLines(SiteDiary $diary): SiteDiary
    {
        return $diary->load([
            'site:id,name',
            'labours.trade:id,name',
            'equipment.equipmentType:id,name',
            'materials.material:id,code,name',
            'materials.unit:id,symbol',
            'photos',
        ]);
    }

    private function assertOnProject(Project $project, SiteDiary $diary): void
    {
        abort_unless((int) $diary->project_id === (int) $project->id, 404);
    }
}

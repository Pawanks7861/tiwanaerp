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
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteDiaryController extends Controller
{
    public function __construct(
        private readonly SiteDiaryService $diaries,
        private readonly SiteDiaryPhotoService $photos,
        private readonly LargeFileUploadService $uploads,
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
            $this->storePhotos($request, $existing);

            return (new SiteDiaryResource($this->withLines($existing)))->response();
        }

        $diary = $this->diaries->create($project, $data);
        $this->storePhotos($request, $diary);

        if ($request->boolean('submit') || ($request->hasFile('photos') && $diary->fresh()?->isEditable() && filled($data['work_performed'] ?? null))) {
            if ($diary->fresh()?->isEditable() && Gate::allows('submit', $diary)) {
                try {
                    $this->diaries->submit($diary, $request->user());
                } catch (ValidationException) {
                    // Keep draft when submit criteria not met (e.g. no work text/items).
                }
            }
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

    public function submit(Request $request, Project $project, SiteDiary $siteDiary): SiteDiaryResource
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('submit', $siteDiary);
        $this->diaries->submit($siteDiary, $request->user());

        return new SiteDiaryResource($this->withLines($siteDiary->fresh()));
    }

    public function review(Request $request, Project $project, SiteDiary $siteDiary): SiteDiaryResource
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('review', $siteDiary);
        $this->diaries->review($siteDiary, $request->user());

        return new SiteDiaryResource($this->withLines($siteDiary->fresh()));
    }

    public function approve(Request $request, Project $project, SiteDiary $siteDiary): SiteDiaryResource
    {
        $this->assertOnProject($project, $siteDiary);
        if ($siteDiary->status !== SiteDiaryStatus::Approved) {
            Gate::authorize('approve', $siteDiary);
        } else {
            abort_unless(
                $request->user()->can('site_diary.approve') && Gate::allows('view', $siteDiary),
                403
            );
        }
        $this->diaries->approve($siteDiary, $request->user());

        return new SiteDiaryResource($this->withLines($siteDiary->fresh()));
    }

    public function reject(Request $request, Project $project, SiteDiary $siteDiary): SiteDiaryResource
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('reject', $siteDiary);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->diaries->reject($siteDiary, $request->user(), $reason);

        return new SiteDiaryResource($this->withLines($siteDiary->fresh()));
    }

    public function storePhoto(Request $request, Project $project, SiteDiary $siteDiary): JsonResponse
    {
        $this->assertOnProject($project, $siteDiary);
        Gate::authorize('update', $siteDiary);

        if ($request->filled('upload_id')) {
            $data = $request->validate([
                'upload_id' => ['required', 'uuid'],
                'uuid' => ['nullable', 'uuid'],
                'caption' => ['nullable', 'string', 'max:255'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            ]);
            $file = $this->uploads->claim($request->user(), $data['upload_id']);
            $photo = $this->photos->store($siteDiary, $file, [
                'uuid' => $data['uuid'] ?? null,
                'caption' => $data['caption'] ?? null,
                'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 7) : null,
                'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 7) : null,
                'taken_at' => $data['taken_at'] ?? null,
            ]);
            $this->uploads->release($request->user(), $data['upload_id']);

            return response()->json(['data' => $this->photoPayload($project, $siteDiary, $photo)], 201);
        }

        $maxKb = (int) config('uploads.photo_max_kb');
        $data = $request->validate([
            'photo' => ['required', 'file', "max:{$maxKb}", 'extensions:'.implode(',', config('uploads.image_extensions'))],
            'uuid' => ['nullable', 'uuid'],
            'caption' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
        ]);

        $photo = $this->photos->store($siteDiary, $request->file('photo'), [
            'uuid' => $data['uuid'] ?? null,
            'caption' => $data['caption'] ?? null,
            'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 7) : null,
            'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 7) : null,
            'taken_at' => $data['taken_at'] ?? null,
        ]);

        return response()->json(['data' => $this->photoPayload($project, $siteDiary, $photo)], 201);
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
        Gate::authorize('update', $siteDiary);
        $this->photos->delete($photo);

        return response()->json(['message' => 'Photo removed.']);
    }

    private function storePhotos(Request $request, SiteDiary $diary): void
    {
        if (! $diary->isEditable()) {
            return;
        }

        $uuids = $request->input('photo_uuids', []);
        $uuids = is_array($uuids) ? array_values($uuids) : [];

        if ($request->hasFile('photos')) {
            $files = $request->file('photos');
            $files = is_array($files) ? $files : [$files];
            foreach ($files as $i => $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $this->photos->store($diary, $file, [
                        'uuid' => is_string($uuids[$i] ?? null) ? $uuids[$i] : null,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function photoPayload(Project $project, SiteDiary $diary, SiteDiaryPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'uuid' => $photo->uuid,
            'caption' => $photo->caption,
            'mime' => $photo->mime,
            'size' => $photo->size_bytes,
            'latitude' => $photo->latitude,
            'longitude' => $photo->longitude,
            'taken_at' => $photo->taken_at?->toIso8601String(),
            'url' => route('api.v1.projects.site-diaries.photos.show', [
                'project' => $project->id,
                'siteDiary' => $diary->id,
                'photo' => $photo->id,
            ]),
        ];
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
}

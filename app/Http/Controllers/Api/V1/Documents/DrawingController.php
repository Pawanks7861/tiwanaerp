<?php

namespace App\Http\Controllers\Api\V1\Documents;

use App\Enums\Discipline;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Documents\DrawingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawingResource;
use App\Http\Resources\DrawingRevisionResource;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Services\Attachments\FileTypeGuard;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Documents\DrawingService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DrawingController extends Controller
{
    public function __construct(
        private readonly DrawingService $drawings,
        private readonly PrivateFileStore $files,
        private readonly LargeFileUploadService $uploads,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Drawing::class, $project]);

        $filters = $request->validate([
            'discipline' => ['nullable', Rule::enum(Discipline::class)],
            'status' => ['nullable', Rule::enum(DrawingStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $page = $project->drawings()
            ->with(['currentRevision:id,revision_code'])
            ->withCount('revisions')
            ->addSelect(['open_revision' => DrawingRevision::query()->select('status')
                ->whereColumn('drawing_id', 'drawings.id')
                ->whereIn('status', [DrawingRevisionStatus::Draft->value, DrawingRevisionStatus::Submitted->value, DrawingRevisionStatus::UnderReview->value])
                ->whereNull('deleted_at')
                ->limit(1)])
            ->when($filters['discipline'] ?? null, fn ($q, $d) => $q->where('discipline', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('drawing_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('drawing_number')
            ->paginate($perPage);

        return DrawingResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Drawing::class, $project]);

        $data = $request->validate([
            'drawing_number' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            'revision_code' => ['required', 'string', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            ...$this->fileRules(),
        ]);
        $file = $this->uploads->fileFromRequest($request);
        [$drawing, $revision] = $this->drawings->create($project, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));

        return (new DrawingResource($this->present($drawing, $revision)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Drawing $drawing): DrawingResource
    {
        $this->assertDrawing($project, $drawing);
        Gate::authorize('view', $drawing);

        $drawing->load('currentRevision:id,revision_code')->loadCount('revisions');
        $revisions = DrawingRevision::query()->withTrashed()->where('drawing_id', $drawing->id)
            ->with(['uploader:id,name', 'submitter:id,name', 'reviewer:id,name', 'decider:id,name', 'supersedes:id,revision_code', 'drawing:id,project_id,current_revision_id'])
            ->orderByDesc('id')
            ->get();
        $drawing->setRelation('revisions', $revisions);

        return new DrawingResource($drawing);
    }

    public function storeRevision(Request $request, Project $project, Drawing $drawing): JsonResponse
    {
        $this->assertDrawing($project, $drawing);
        Gate::authorize('upload', $drawing);

        $data = $request->validate([
            'revision_code' => ['required', 'string', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            ...$this->fileRules(),
        ]);
        $file = $this->uploads->fileFromRequest($request);
        $revision = $this->drawings->uploadRevision($drawing, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));

        return (new DrawingRevisionResource($this->presentRevision($revision)))
            ->additional(['duplicate_of' => $this->drawings->duplicateOf($revision)])
            ->response()
            ->setStatusCode(201);
    }

    public function submit(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): DrawingRevisionResource
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('upload', $drawing);

        $this->drawings->submit($revision, $request->user());

        return new DrawingRevisionResource($this->presentRevision($revision));
    }

    public function review(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): DrawingRevisionResource
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('review', $drawing);

        $this->drawings->startReview($revision, $request->user());

        return new DrawingRevisionResource($this->presentRevision($revision));
    }

    public function approve(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): DrawingRevisionResource
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('approve', $drawing);

        $comments = $request->validate(['comments' => ['nullable', 'string', 'max:1000']])['comments'] ?? null;
        $this->drawings->approve($revision, $comments, $request->user());

        return new DrawingRevisionResource($this->presentRevision($revision));
    }

    public function reject(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): DrawingRevisionResource
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('reject', $drawing);

        $comments = $request->validate(['comments' => ['required', 'string', 'min:5', 'max:1000']])['comments'];
        $this->drawings->reject($revision, $comments, $request->user());

        return new DrawingRevisionResource($this->presentRevision($revision));
    }

    public function withdraw(Project $project, Drawing $drawing, DrawingRevision $revision): DrawingRevisionResource
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('upload', $drawing);

        $this->drawings->withdraw($revision);

        return new DrawingRevisionResource($this->presentRevision($revision));
    }

    public function download(Project $project, Drawing $drawing, DrawingRevision $revision): StreamedResponse
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('view', $drawing);

        return $this->files->respond($revision, inline: false);
    }

    public function preview(Project $project, Drawing $drawing, DrawingRevision $revision): StreamedResponse
    {
        $this->assertRevision($project, $drawing, $revision);
        Gate::authorize('view', $drawing);
        abort_unless(PrivateFileStore::isPreviewable($revision->extension), 404);

        return $this->files->respond($revision, inline: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileRules(): array
    {
        return FileTypeGuard::fileRules();
    }

    private function present(Drawing $drawing, DrawingRevision $revision): Drawing
    {
        $drawing->load('currentRevision:id,revision_code')->loadCount('revisions');
        $drawing->setRelation('revisions', collect([$this->presentRevision($revision)]));

        return $drawing;
    }

    private function presentRevision(DrawingRevision $revision): DrawingRevision
    {
        $revision->load([
            'drawing:id,project_id,current_revision_id',
            'uploader:id,name',
            'submitter:id,name',
            'reviewer:id,name',
            'decider:id,name',
            'supersedes:id,revision_code',
        ]);

        return $revision;
    }

    private function assertDrawing(Project $project, Drawing $drawing): void
    {
        abort_unless((int) $drawing->project_id === (int) $project->id, 404);
    }

    private function assertRevision(Project $project, Drawing $drawing, DrawingRevision $revision): void
    {
        $this->assertDrawing($project, $drawing);
        abort_unless((int) $revision->drawing_id === (int) $drawing->id, 404);
    }
}

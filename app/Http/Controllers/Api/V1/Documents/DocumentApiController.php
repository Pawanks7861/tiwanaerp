<?php

namespace App\Http\Controllers\Api\V1\Documents;

use App\Enums\Documents\DocumentCategory;
use App\Enums\Documents\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\DocumentVersion;
use App\Models\Projects\Project;
use App\Services\Attachments\FileTypeGuard;
use App\Services\Documents\DocumentService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DocumentApiController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly LargeFileUploadService $uploads,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Document::class, $project]);

        $filters = $request->validate([
            'folder' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'extension' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $folderId = isset($filters['folder']) ? (int) $filters['folder'] : null;
        if ($folderId) {
            abort_unless(DocumentFolder::query()->where('project_id', $project->id)->whereKey($folderId)->exists(), 404);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        $extension = ltrim(strtolower(trim((string) ($filters['extension'] ?? ''))), '.');

        $page = $project->documents()
            ->with(['folder:id,name,parent_id', 'currentVersion'])
            ->when($folderId !== null, fn ($q) => $folderId === 0 ? $q->whereNull('document_folder_id') : $q->where('document_folder_id', $folderId))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($extension !== '', fn ($q) => $q->whereHas('currentVersion', fn ($v) => $v->where('extension', $extension)))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('document_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('reference_no', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('updated_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return DocumentResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $data = $request->validate([...$this->detailRules(), ...$this->versionRules()]);
        $file = $this->uploads->fileFromRequest($request);
        [$document] = $this->documents->create($project, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));

        return (new DocumentResource($this->detail($document)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Document $document): DocumentResource
    {
        $this->assertDocument($project, $document);
        Gate::authorize('view', $document);

        return new DocumentResource($this->detail($document));
    }

    public function update(Request $request, Project $project, Document $document): DocumentResource
    {
        $this->assertDocument($project, $document);
        Gate::authorize('update', $document);

        $this->documents->update($document, $request->validate($this->detailRules()));

        return new DocumentResource($this->detail($document->fresh()));
    }

    public function destroy(Project $project, Document $document): JsonResponse
    {
        $this->assertDocument($project, $document);
        Gate::authorize('delete', $document);

        $this->documents->delete($document);

        return response()->json(['message' => 'Document deleted.']);
    }

    public function storeVersion(Request $request, Project $project, Document $document): JsonResponse
    {
        $this->assertDocument($project, $document);
        Gate::authorize('addVersion', $document);

        $data = $request->validate($this->versionRules());
        $file = $this->uploads->fileFromRequest($request);
        $version = $this->documents->addVersion($document, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));

        return (new DocumentVersionResource($version->load(['uploader:id,name'])->setRelation('document', $document->fresh())))
            ->additional(['duplicate_of' => $this->documents->duplicateOf($version)])
            ->response()
            ->setStatusCode(201);
    }

    public function publish(Project $project, Document $document): DocumentResource
    {
        $this->assertDocument($project, $document);
        Gate::authorize('publish', $document);

        $this->documents->publish($document);

        return new DocumentResource($this->detail($document->fresh()));
    }

    public function archive(Request $request, Project $project, Document $document): DocumentResource
    {
        $this->assertDocument($project, $document);
        Gate::authorize('archive', $document);

        $this->documents->archive($document, $request->user());

        return new DocumentResource($this->detail($document->fresh()));
    }

    public function restore(Project $project, Document $document): DocumentResource
    {
        $this->assertDocument($project, $document);
        Gate::authorize('restore', $document);

        $this->documents->restore($document);

        return new DocumentResource($this->detail($document->fresh()));
    }

    private function detail(Document $document): Document
    {
        $document->load([
            'folder:id,name,parent_id',
            'currentVersion.uploader:id,name',
            'creator:id,name',
            'archiver:id,name',
            'versions.uploader:id,name',
        ]);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'document_folder_id' => ['nullable', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionRules(): array
    {
        return [
            ...FileTypeGuard::fileRules(),
            'revision_label' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function assertDocument(Project $project, Document $document): void
    {
        abort_unless((int) $document->project_id === (int) $project->id, 404);
    }
}

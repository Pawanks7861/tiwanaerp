<?php

namespace App\Http\Controllers\Documents;

use App\Enums\Documents\DocumentCategory;
use App\Enums\Documents\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\DocumentVersion;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Attachments\FileTypeGuard;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Documents\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly PrivateFileStore $files,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Document::class, $project]);
        $user = $request->user();

        $filters = $request->validate([
            'folder' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $folderId = isset($filters['folder']) ? (int) $filters['folder'] : null;
        if ($folderId) {
            abort_unless(DocumentFolder::query()->where('project_id', $project->id)->whereKey($folderId)->exists(), 404);
        }

        $page = $project->documents()
            ->with(['folder:id,name', 'currentVersion:id,version_no,file_name,extension,size_bytes,created_at'])
            ->when($folderId !== null, fn ($q) => $folderId === 0 ? $q->whereNull('document_folder_id') : $q->where('document_folder_id', $folderId))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('document_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('reference_no', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('updated_at')->latest('id')->paginate(25)->withQueryString();

        $counts = $project->documents()->selectRaw('document_folder_id, count(*) as aggregate')->groupBy('document_folder_id')->pluck('aggregate', 'document_folder_id');

        return Inertia::render('Documents/Library/Index', [
            'project' => ProjectHeader::for($project),
            'documents' => $page->through(fn (Document $d) => [
                ...$this->header($d),
                'folder' => $d->folder?->name,
                'current_version' => $d->currentVersion ? [
                    'version_no' => $d->currentVersion->version_no,
                    'file_name' => $d->currentVersion->file_name,
                    'size_bytes' => $d->currentVersion->size_bytes,
                    'uploaded_at' => $d->currentVersion->created_at?->toIso8601String(),
                ] : null,
            ]),
            'folders' => $project->documentFolders()->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name'])
                ->map(fn (DocumentFolder $f) => [...$f->only(['id', 'parent_id', 'name']), 'documents_count' => (int) ($counts[$f->id] ?? 0)])->all(),
            'unfiledCount' => (int) $counts->get('', 0),
            'filters' => $filters,
            'statuses' => DocumentStatus::options(),
            'categories' => DocumentCategory::options(),
            'can' => [
                'create' => $user->can('create', [Document::class, $project]),
                'manageFolders' => $user->can('manageFolders', [Document::class, $project]),
            ],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [Document::class, $project]);

        return Inertia::render('Documents/Library/Create', [
            'project' => ProjectHeader::for($project),
            'folders' => $this->folderOptions($project),
            'defaultFolderId' => $request->integer('folder') ?: null,
            'categories' => DocumentCategory::options(),
            'extensions' => FileTypeGuard::extensions(),
            'maxMb' => (int) floor(config('uploads.max_kb') / 1024),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $data = $request->validate([...$this->detailRules(), ...$this->versionRules()]);
        [$document] = $this->documents->create($project, $data, $request->file('file'), $request->user());

        return redirect()->route('projects.documents.show', [$project, $document])
            ->with('success', "Document {$document->document_number} created as draft (version 1). Publish it when ready.");
    }

    public function show(Request $request, Project $project, Document $document): Response
    {
        Gate::authorize('view', $document);
        $user = $request->user();
        $document->load(['folder:id,name', 'archiver:id,name', 'creator:id,name']);
        $versions = $document->versions()->with('uploader:id,name')->get();
        $firstByChecksum = $versions->sortBy('version_no')->groupBy('checksum')->map(fn ($group) => $group->first()->version_no);

        return Inertia::render('Documents/Library/Show', [
            'project' => ProjectHeader::for($project),
            'document' => [
                ...$this->header($document),
                ...$document->only(['description', 'document_folder_id']),
                'folder' => $document->folder?->name,
                'created_by' => $document->creator?->name,
                'created_at' => $document->created_at?->toIso8601String(),
                'archived_by' => $document->archiver?->name,
                'archived_at' => $document->archived_at?->toIso8601String(),
                'current_version_id' => $document->current_version_id,
            ],
            'versions' => $versions->map(fn (DocumentVersion $v) => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'revision_label' => $v->revision_label,
                'file_name' => $v->file_name,
                'extension' => $v->extension,
                'size_bytes' => $v->size_bytes,
                'checksum' => $v->checksum,
                'notes' => $v->notes,
                'uploaded_by' => $v->uploader?->name,
                'uploaded_at' => $v->created_at?->toIso8601String(),
                'is_current' => $v->id === $document->current_version_id,
                'duplicate_of' => ($first = $firstByChecksum[$v->checksum] ?? null) !== null && $first !== $v->version_no ? $first : null,
                'download_url' => route('projects.documents.versions.download', [$project, $document, $v]),
                'preview_url' => PrivateFileStore::isPreviewable($v->extension) ? route('projects.documents.versions.preview', [$project, $document, $v]) : null,
            ])->all(),
            'folders' => $this->folderOptions($project),
            'categories' => DocumentCategory::options(),
            'extensions' => FileTypeGuard::extensions(),
            'maxMb' => (int) floor(config('uploads.max_kb') / 1024),
            'audit' => AuditPresenter::trail([$document, ...$versions->all()], ['document_version' => 'Version']),
            'can' => $this->abilities($user, $document),
        ]);
    }

    public function update(Request $request, Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('update', $document);

        $this->documents->update($document, $request->validate($this->detailRules()));

        return back()->with('success', 'Document details saved.');
    }

    public function storeVersion(Request $request, Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('addVersion', $document);

        $version = $this->documents->addVersion($document, $request->validate($this->versionRules()), $request->file('file'), $request->user());
        $duplicate = $this->documents->duplicateOf($version);

        return back()->with('success', "Version {$version->version_no} uploaded and is now current."
            .($duplicate ? " Note: the file is identical to version {$duplicate}." : ''));
    }

    public function publish(Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('publish', $document);

        $this->documents->publish($document);

        return back()->with('success', "Document {$document->document_number} published.");
    }

    public function archive(Request $request, Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('archive', $document);

        $this->documents->archive($document, $request->user());

        return back()->with('success', "Document {$document->document_number} archived. Its versions remain available.");
    }

    public function restore(Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('restore', $document);

        $this->documents->restore($document);

        return back()->with('success', "Document {$document->document_number} restored.");
    }

    public function destroy(Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $this->documents->delete($document);

        return redirect()->route('projects.documents.index', $project)->with('success', "Draft document {$document->document_number} deleted.");
    }

    public function download(Project $project, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $document);

        return $this->files->respond($version, inline: false);
    }

    public function preview(Project $project, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless(PrivateFileStore::isPreviewable($version->extension), 404);

        return $this->files->respond($version, inline: true);
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
        $maxKb = (int) config('uploads.max_kb');

        return [
            'file' => ['required', 'file', "max:{$maxKb}", 'extensions:'.implode(',', FileTypeGuard::extensions())],
            'revision_label' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Folder options with their full path, for selects.
     *
     * @return list<array{value: int, label: string}>
     */
    private function folderOptions(Project $project): array
    {
        $folders = $project->documentFolders()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $path = function (DocumentFolder $folder) use ($folders): string {
            $parts = [$folder->name];
            $seen = [$folder->id => true];
            for ($p = $folders->get($folder->parent_id); $p !== null && ! isset($seen[$p->id]); $p = $folders->get($p->parent_id)) {
                $seen[$p->id] = true;
                array_unshift($parts, $p->name);
            }

            return implode(' / ', $parts);
        };

        return $folders->map(fn (DocumentFolder $f) => ['value' => $f->id, 'label' => $path($f)])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Document $document): array
    {
        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'reference_no' => $document->reference_no,
            'name' => $document->name,
            'category' => $document->category->value,
            'category_label' => $document->category->label(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
        ];
    }

    /**
     * Permission AND document state (platform super admins pass every policy check).
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, Document $document): array
    {
        $status = $document->status;

        return [
            'update' => $status !== DocumentStatus::Archived && $user->can('update', $document),
            'addVersion' => $status->acceptsVersions() && $user->can('addVersion', $document),
            'publish' => $status === DocumentStatus::Draft && $user->can('publish', $document),
            'archive' => $status === DocumentStatus::Active && $user->can('archive', $document),
            'restore' => $status === DocumentStatus::Archived && $user->can('restore', $document),
            'delete' => $status === DocumentStatus::Draft && $user->can('delete', $document),
        ];
    }
}

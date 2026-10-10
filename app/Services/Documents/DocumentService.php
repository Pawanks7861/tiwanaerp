<?php

namespace App\Services\Documents;

use App\Enums\Documents\DocumentCategory;
use App\Enums\Documents\DocumentStatus;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\DocumentVersion;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Files\FilePreviewService;
use App\Services\Numbering\DocumentNumberService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Document library: logical folders, documents (draft → active ⇄ archived) and immutable versions.
 * version_no is assigned here under a row lock on the document (1, 2, 3, ...) and
 * current_version_id moves in the same transaction. Versions are never updated or deleted;
 * deleting a draft document is a soft delete that keeps its versions and files.
 */
class DocumentService
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param  array{name: string, category: string, document_folder_id?: ?int, reference_no?: ?string, description?: ?string, revision_label?: ?string, notes?: ?string}  $data
     * @return array{0: Document, 1: DocumentVersion}
     */
    public function create(Project $project, array $data, UploadedFile $file, User $user): array
    {
        return $this->withStoredFile($file, $project, function (array $stored) use ($project, $data, $user) {
            $document = new Document;
            $document->forceFill([
                'project_id' => $project->id,
                'document_number' => $this->numbers->next('document', $project),
                'status' => DocumentStatus::Draft,
                ...$this->details($project, $data),
            ])->save();

            $version = $this->newVersion($document, 1, $stored, $data, $user);

            return [$document, $version];
        });
    }

    /**
     * @param  array{name: string, category: string, document_folder_id?: ?int, reference_no?: ?string, description?: ?string}  $data
     */
    public function update(Document $document, array $data): Document
    {
        return DB::transaction(function () use ($document, $data) {
            $locked = $this->lock($document);
            $locked->assertEditable();
            $locked->forceFill($this->details($locked->project, $data))->save();

            return $locked;
        });
    }

    /**
     * @param  array{revision_label?: ?string, notes?: ?string}  $data
     */
    public function addVersion(Document $document, array $data, UploadedFile $file, User $user): DocumentVersion
    {
        return $this->withStoredFile($file, $document->project, function (array $stored) use ($document, $data, $user) {
            $locked = $this->lock($document);
            if (! $locked->status->acceptsVersions()) {
                throw ValidationException::withMessages(['document' => 'An archived document cannot take new versions. Restore it first.']);
            }

            $next = (int) DocumentVersion::query()->where('document_id', $locked->id)->max('version_no') + 1;

            return $this->newVersion($locked, $next, $stored, $data, $user);
        });
    }

    public function publish(Document $document): void
    {
        $this->transition($document, DocumentStatus::Draft, 'Only a draft document can be published.', function (Document $d) {
            $d->forceFill(['status' => DocumentStatus::Active])->save();
        });
    }

    public function archive(Document $document, User $user): void
    {
        $this->transition($document, DocumentStatus::Active, 'Only an active document can be archived.', function (Document $d) use ($user) {
            $d->forceFill(['status' => DocumentStatus::Archived, 'archived_by' => $user->id, 'archived_at' => now()])->save();
        });
    }

    public function restore(Document $document): void
    {
        $this->transition($document, DocumentStatus::Archived, 'Only an archived document can be restored.', function (Document $d) {
            $d->forceFill(['status' => DocumentStatus::Active, 'archived_by' => null, 'archived_at' => null])->save();
        });
    }

    public function delete(Document $document): void
    {
        $this->transition($document, DocumentStatus::Draft, 'Only a draft document can be deleted; archive an active one instead.', function (Document $d) {
            $d->delete();
        });
    }

    /**
     * Earlier version of the same document with identical content (flag only, never rejected).
     */
    public function duplicateOf(DocumentVersion $version): ?int
    {
        $no = DocumentVersion::query()
            ->where('document_id', $version->document_id)
            ->where('checksum', $version->checksum)
            ->whereKeyNot($version->id)
            ->min('version_no');

        return $no === null ? null : (int) $no;
    }

    /**
     * @param  array{name: string, parent_id?: ?int}  $data
     */
    public function createFolder(Project $project, array $data): DocumentFolder
    {
        return DB::transaction(function () use ($project, $data) {
            $parent = $this->folder($project, $data['parent_id'] ?? null, 'parent_id');
            $name = $this->folderName($project, $data['name'] ?? '', $parent?->id);

            $folder = new DocumentFolder;
            $folder->forceFill(['project_id' => $project->id, 'parent_id' => $parent?->id, 'name' => $name])->save();

            return $folder;
        });
    }

    /**
     * Rename and / or move a folder inside the same project, never under itself.
     *
     * @param  array{name: string, parent_id?: ?int}  $data
     */
    public function updateFolder(DocumentFolder $folder, array $data): DocumentFolder
    {
        return DB::transaction(function () use ($folder, $data) {
            $locked = DocumentFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();
            $project = $locked->project;
            $parent = $this->folder($project, $data['parent_id'] ?? null, 'parent_id');

            for ($cursor = $parent; $cursor !== null; $cursor = $cursor->parent_id ? DocumentFolder::query()->find($cursor->parent_id) : null) {
                if ($cursor->id === $locked->id) {
                    throw ValidationException::withMessages(['parent_id' => 'A folder cannot be moved inside itself.']);
                }
            }

            $name = $this->folderName($project, $data['name'] ?? '', $parent?->id, $locked->id);
            $locked->forceFill(['parent_id' => $parent?->id, 'name' => $name])->save();

            return $locked;
        });
    }

    public function deleteFolder(DocumentFolder $folder): void
    {
        DB::transaction(function () use ($folder) {
            $locked = DocumentFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();
            $hasChildren = DocumentFolder::query()->where('parent_id', $locked->id)->exists();
            $hasDocuments = Document::query()->withTrashed()->where('document_folder_id', $locked->id)->exists();
            if ($hasChildren || $hasDocuments) {
                throw ValidationException::withMessages(['folder' => 'Only an empty folder can be deleted. Move its documents and subfolders first.']);
            }
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(Project $project, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the document name.']);
        }

        return [
            'name' => $name,
            'category' => DocumentCategory::tryFrom((string) ($data['category'] ?? ''))
                ?? throw ValidationException::withMessages(['category' => 'Choose a category.']),
            'document_folder_id' => $this->folder($project, $data['document_folder_id'] ?? null, 'document_folder_id')?->id,
            'reference_no' => filled($data['reference_no'] ?? null) ? trim($data['reference_no']) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $data
     */
    private function newVersion(Document $document, int $versionNo, array $stored, array $data, User $user): DocumentVersion
    {
        $version = new DocumentVersion;
        $version->forceFill([
            'document_id' => $document->id,
            'version_no' => $versionNo,
            'revision_label' => filled($data['revision_label'] ?? null) ? trim($data['revision_label']) : null,
            ...$stored,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'uploaded_by' => $user->id,
        ])->save();

        $previous = $document->current_version_id;
        $document->forceFill(['current_version_id' => $version->id])->save();
        $document->writeAudit('version_added', $previous ? ['current_version_id' => $previous] : null, [
            'version_no' => $versionNo,
            'file' => $stored['file_name'],
            'checksum' => $stored['checksum'],
        ]);
        app(FilePreviewService::class)->enqueue('document_version', (int) $version->id);

        return $version;
    }

    private function folder(Project $project, mixed $id, string $key): ?DocumentFolder
    {
        if (blank($id)) {
            return null;
        }

        $folder = is_numeric($id) ? DocumentFolder::query()->where('project_id', $project->id)->whereKey((int) $id)->first() : null;

        return $folder ?? throw ValidationException::withMessages([$key => 'Choose a folder of this project.']);
    }

    private function folderName(Project $project, string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\\\\\/]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Enter a folder name of up to 120 characters, without slashes.']);
        }

        $taken = DocumentFolder::query()->where('project_id', $project->id)
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'A folder with this name already exists here.']);
        }

        return $name;
    }

    /**
     * @param  Closure(Document): void  $apply
     */
    private function transition(Document $document, DocumentStatus $from, string $message, Closure $apply): void
    {
        DB::transaction(function () use ($document, $from, $message, $apply) {
            $locked = $this->lock($document);
            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['document' => $message]);
            }
            $apply($locked);
            $document->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function lock(Document $document): Document
    {
        return Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $write
     * @return T
     */
    private function withStoredFile(UploadedFile $file, Project $project, Closure $write): mixed
    {
        $stored = $this->files->put($file, (int) $project->company_id, (int) $project->id, 'documents');

        try {
            return DB::transaction(fn () => $write($stored));
        } catch (Throwable $e) {
            $this->files->discard($stored);
            throw $e;
        }
    }
}

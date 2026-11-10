<?php

namespace App\Http\Resources;

use App\Enums\Documents\DocumentCategory;
use App\Enums\Documents\DocumentStatus;
use App\Models\Documents\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;

        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'reference_no' => $this->reference_no,
            'name' => $this->name,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'description' => $this->description,
            'document_folder_id' => $this->document_folder_id,
            'folder' => $this->whenLoaded('folder', fn () => $this->folder?->only(['id', 'name', 'parent_id'])),
            'current_version_id' => $this->current_version_id,
            'current_version' => $this->when(
                $this->relationLoaded('currentVersion') && $this->currentVersion,
                fn () => DocumentVersionResource::make($this->currentVersion->setRelation('document', $this->resource))->resolve(),
            ),
            'versions' => $this->whenLoaded('versions', function () {
                $document = $this->resource;

                return $this->versions
                    ->sortByDesc('version_no')
                    ->values()
                    ->map(fn ($version) => DocumentVersionResource::make($version->setRelation('document', $document))->resolve());
            }),
            'versions_count' => $this->whenCounted('versions'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived_by' => $this->whenLoaded('archiver', fn () => $this->archiver?->only(['id', 'name'])),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'meta' => $this->when($this->relationLoaded('versions'), fn () => [
                'categories' => DocumentCategory::options(),
            ]),
            'can' => $user ? $this->abilities($user) : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        /** @var Document $document */
        $document = $this->resource;
        $status = $document->status;

        $canView = $user->can('view', $document);

        return [
            'update' => $status !== DocumentStatus::Archived && $user->can('update', $document),
            'revise' => $status->acceptsVersions() && $user->can('addVersion', $document),
            'add_version' => $status->acceptsVersions() && $user->can('addVersion', $document),
            'publish' => $status === DocumentStatus::Draft && $user->can('publish', $document),
            'activate' => $status === DocumentStatus::Draft && $user->can('publish', $document),
            'archive' => $status === DocumentStatus::Active && $user->can('archive', $document),
            'restore' => $status === DocumentStatus::Archived && $user->can('restore', $document),
            'delete' => $status === DocumentStatus::Draft && $user->can('delete', $document),
            'download' => $canView && $document->current_version_id !== null,
            'preview' => $canView && $document->current_version_id !== null,
        ];
    }
}

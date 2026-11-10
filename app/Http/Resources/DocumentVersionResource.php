<?php

namespace App\Http\Resources;

use App\Models\Documents\DocumentVersion;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Files\FilePreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentVersion
 */
class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $document = $this->relationLoaded('document') ? $this->document : null;

        return [
            'id' => $this->id,
            'version_no' => $this->version_no,
            'revision_label' => $this->revision_label,
            'file_name' => $this->file_name,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'notes' => $this->notes,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->only(['id', 'name'])),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'is_current' => $document !== null && (int) $this->id === (int) $document->current_version_id,
            'previewable' => PrivateFileStore::isPreviewable((string) $this->extension),
            'preview' => app(FilePreviewService::class)->card('document_version', (int) $this->id, (string) $this->extension),
            'download_url' => route('api.v1.files.download', ['source' => 'document_version', 'id' => $this->id]),
            'preview_url' => PrivateFileStore::isPreviewable((string) $this->extension)
                ? route('api.v1.files.preview', ['source' => 'document_version', 'id' => $this->id])
                : null,
        ];
    }
}

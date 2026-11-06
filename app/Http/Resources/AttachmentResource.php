<?php

namespace App\Http\Resources;

use App\Models\Core\Attachment;
use App\Services\Files\FilePreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lineUid = null;
        if ($this->relationLoaded('attachable') && $this->attachable && isset($this->attachable->line_uid)) {
            $lineUid = (string) $this->attachable->line_uid;
        }

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'extension' => $this->extension,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'category' => $this->category,
            'line_uid' => $lineUid,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'download_url' => route('attachments.download', $this->id),
            'preview' => app(FilePreviewService::class)->card('attachment', (int) $this->id, (string) $this->extension),
        ];
    }
}

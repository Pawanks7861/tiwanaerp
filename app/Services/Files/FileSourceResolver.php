<?php

namespace App\Services\Files;

use App\Models\Chat\MessageAttachment;
use App\Models\Core\Attachment;
use App\Models\Documents\DocumentVersion;
use App\Models\Documents\DrawingRevision;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Gate;

/**
 * Loads one private file and re-checks the parent record. Another company is a 404 via company scope.
 */
class FileSourceResolver
{
    public const SOURCES = ['attachment', 'document_version', 'drawing_revision', 'chat', 'site_photo'];

    public function __construct(private readonly ChatService $chat) {}

    public function authorize(string $source, int $id): PreviewableFile
    {
        abort_unless(in_array($source, self::SOURCES, true), 404);

        $file = $this->locate($source, $id);
        $this->assertCanView($source, $id);

        return $file;
    }

    public function locate(string $source, int $id): PreviewableFile
    {
        abort_unless(in_array($source, self::SOURCES, true), 404);

        return match ($source) {
            'attachment' => $this->attachment(Attachment::query()->findOrFail($id)),
            'document_version' => $this->documentVersion(DocumentVersion::query()->findOrFail($id)),
            'drawing_revision' => $this->drawingRevision(DrawingRevision::query()->findOrFail($id)),
            'chat' => $this->chatFile(MessageAttachment::query()->findOrFail($id)),
            'site_photo' => $this->photo(SiteDiaryPhoto::query()->findOrFail($id)),
        };
    }

    private function assertCanView(string $source, int $id): void
    {
        if ($source === 'attachment') {
            Gate::authorize('view', Attachment::query()->findOrFail($id));

            return;
        }

        if ($source === 'document_version') {
            $version = DocumentVersion::query()->findOrFail($id);
            abort_unless($version->document !== null, 404);
            Gate::authorize('view', $version->document);

            return;
        }

        if ($source === 'drawing_revision') {
            $revision = DrawingRevision::query()->findOrFail($id);
            abort_unless($revision->drawing !== null, 404);
            Gate::authorize('view', $revision->drawing);

            return;
        }

        if ($source === 'chat') {
            $attachment = MessageAttachment::query()->with('message.conversation')->findOrFail($id);
            abort_unless($attachment->message?->conversation !== null, 404);
            $this->chat->participant($attachment->message->conversation, request()->user());

            return;
        }

        $photo = SiteDiaryPhoto::query()->findOrFail($id);
        abort_unless($photo->diary !== null, 404);
        Gate::authorize('view', $photo->diary);
    }

    private function attachment(Attachment $attachment): PreviewableFile
    {
        $attachment->loadMissing('uploader:id,name');

        return new PreviewableFile(
            'attachment',
            (int) $attachment->id,
            (int) $attachment->company_id,
            (string) $attachment->disk,
            (string) $attachment->path,
            (string) $attachment->original_name,
            strtolower((string) $attachment->extension),
            (int) $attachment->size_bytes,
            $attachment->checksum,
            $attachment->uploader?->name,
            $attachment->created_at?->toIso8601String(),
        );
    }

    private function documentVersion(DocumentVersion $version): PreviewableFile
    {
        $version->loadMissing('uploader:id,name');

        return new PreviewableFile(
            'document_version',
            (int) $version->id,
            (int) $version->company_id,
            (string) $version->disk,
            (string) $version->file_path,
            (string) $version->file_name,
            strtolower((string) $version->extension),
            (int) $version->size_bytes,
            $version->checksum,
            $version->uploader?->name,
            $version->created_at?->toIso8601String(),
        );
    }

    private function drawingRevision(DrawingRevision $revision): PreviewableFile
    {
        $revision->loadMissing('uploader:id,name');

        return new PreviewableFile(
            'drawing_revision',
            (int) $revision->id,
            (int) $revision->company_id,
            (string) $revision->disk,
            (string) $revision->file_path,
            (string) $revision->file_name,
            strtolower((string) $revision->extension),
            (int) $revision->size_bytes,
            $revision->checksum,
            $revision->uploader?->name,
            $revision->created_at?->toIso8601String(),
        );
    }

    private function chatFile(MessageAttachment $attachment): PreviewableFile
    {
        $attachment->loadMissing('uploader:id,name');

        return new PreviewableFile(
            'chat',
            (int) $attachment->id,
            (int) $attachment->company_id,
            (string) $attachment->disk,
            (string) $attachment->path,
            (string) $attachment->original_name,
            $attachment->extension(),
            (int) $attachment->size_bytes,
            $attachment->checksum,
            $attachment->uploader?->name,
            $attachment->created_at?->toIso8601String(),
        );
    }

    private function photo(SiteDiaryPhoto $photo): PreviewableFile
    {
        $photo->loadMissing('diary', 'creator:id,name');
        abort_unless($photo->diary !== null, 404);

        $extension = strtolower(pathinfo((string) $photo->path, PATHINFO_EXTENSION));

        return new PreviewableFile(
            'site_photo',
            (int) $photo->id,
            (int) $photo->diary->company_id,
            (string) $photo->disk,
            (string) $photo->path,
            ($photo->caption !== null && $photo->caption !== '') ? $photo->caption : 'Site photo.'.$extension,
            $extension,
            (int) $photo->size_bytes,
            null,
            $photo->creator?->name,
            ($photo->taken_at ?? $photo->created_at)?->toIso8601String(),
        );
    }
}

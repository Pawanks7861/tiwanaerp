<?php

namespace App\Http\Resources;

use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\User;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Files\FilePreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DrawingRevision
 */
class DrawingRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $withdrawn = $this->trashed();
        $drawing = $this->relationLoaded('drawing') ? $this->drawing : null;
        $user = $request->user();

        return [
            'id' => $this->id,
            'drawing_id' => $this->drawing_id,
            'revision_code' => $this->revision_code,
            'status' => $withdrawn ? 'withdrawn' : $this->status->value,
            'status_label' => $withdrawn ? 'Withdrawn' : $this->status->label(),
            'is_current' => $drawing !== null && (int) $this->id === (int) $drawing->current_revision_id,
            'file_name' => $this->file_name,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'remarks' => $this->remarks,
            'review_comments' => $this->review_comments,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter?->name),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'supersedes' => $this->whenLoaded('supersedes', fn () => $this->supersedes?->revision_code),
            'previewable' => ! $withdrawn && PrivateFileStore::isPreviewable((string) $this->extension),
            'download_url' => $withdrawn ? null : $this->fileRoute($drawing, 'download'),
            'preview' => $withdrawn ? null : app(FilePreviewService::class)->card('drawing_revision', (int) $this->id, (string) $this->extension),
            'preview_url' => ! $withdrawn && PrivateFileStore::isPreviewable((string) $this->extension)
                ? $this->fileRoute($drawing, 'preview')
                : null,
            'can' => $user ? $this->abilities($user, $drawing, $withdrawn) : [],
        ];
    }

    /**
     * Permission AND revision state (platform super admins pass every policy check).
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, ?Drawing $drawing, bool $withdrawn): array
    {
        $status = $this->status;

        return [
            'submit' => ! $withdrawn && $status === DrawingRevisionStatus::Draft && $drawing && $user->can('upload', $drawing),
            'withdraw' => ! $withdrawn && $status === DrawingRevisionStatus::Draft && $drawing && $user->can('upload', $drawing),
            'review' => ! $withdrawn && $status === DrawingRevisionStatus::Submitted && $drawing && $user->can('review', $drawing),
            'approve' => ! $withdrawn && $status === DrawingRevisionStatus::UnderReview && $drawing && $user->can('approve', $drawing),
            'reject' => ! $withdrawn && $status === DrawingRevisionStatus::UnderReview && $drawing && $user->can('reject', $drawing),
        ];
    }

    private function fileRoute(?Drawing $drawing, string $action): ?string
    {
        if ($drawing === null) {
            return null;
        }

        return route("api.v1.projects.drawings.revisions.{$action}", [
            'project' => $drawing->project_id,
            'drawing' => $drawing->id,
            'revision' => $this->id,
        ]);
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Documents\DrawingStatus;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Drawing
 */
class DrawingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'drawing_number' => $this->drawing_number,
            'title' => $this->title,
            'discipline' => $this->discipline->value,
            'discipline_label' => $this->discipline->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_revision' => $this->whenLoaded('currentRevision', fn () => $this->currentRevision?->revision_code),
            'current_revision_id' => $this->current_revision_id,
            'revisions_count' => $this->whenCounted('revisions'),
            'open_revision_status' => $this->when(
                $this->hasSelectedOpenRevision(),
                fn () => $this->selectedOpenRevision(),
            ),
            'open_revision_label' => $this->when(
                $this->hasSelectedOpenRevision(),
                fn () => filled($open = $this->selectedOpenRevision())
                    ? DrawingRevisionStatus::from((string) $open)->label()
                    : null,
            ),
            'revisions' => $this->whenLoaded(
                'revisions',
                fn () => DrawingRevisionResource::collection($this->revisions)->resolve(),
            ),
            'can' => $this->when($request->user() !== null, fn () => $this->abilities($request->user())),
        ];
    }

    /**
     * Same abilities as the drawing screen: permission and whether a revision is still open.
     *
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        /** @var Drawing $drawing */
        $drawing = $this->resource;

        return [
            'update' => $user->can('upload', $drawing),
            'editNumber' => $drawing->status !== DrawingStatus::Approved && $user->can('upload', $drawing),
            'upload' => ! $this->hasOpenRevision() && $user->can('upload', $drawing),
        ];
    }

    private function hasOpenRevision(): bool
    {
        if ($this->relationLoaded('revisions')) {
            return $this->revisions->contains(
                fn (DrawingRevision $revision) => ! $revision->trashed() && $revision->status->isOpen(),
            );
        }

        return filled($this->selectedOpenRevision());
    }

    private function hasSelectedOpenRevision(): bool
    {
        return array_key_exists('open_revision', $this->resource->getAttributes());
    }

    private function selectedOpenRevision(): mixed
    {
        return $this->hasSelectedOpenRevision()
            ? $this->resource->getAttributes()['open_revision']
            : null;
    }
}

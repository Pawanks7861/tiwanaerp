<?php

namespace App\Http\Resources;

use App\Http\Controllers\Boq\BoqPresenter;
use App\Models\Boq\Boq;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Boq
 */
class BoqResource extends JsonResource
{
    public bool $detailed = false;

    /** @var list<array<string, mixed>>|null */
    public ?array $importHistory = null;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function withHistory(array $rows): static
    {
        $this->importHistory = $rows;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $withCosts = (bool) $user?->can('boq.view_costs');

        $data = [
            ...BoqPresenter::header($this->resource, $withCosts),
            'can' => $user ? BoqPresenter::abilities($user, $this->resource) : [],
        ];

        if (! $this->detailed) {
            return $data;
        }

        $canViewDrawings = $user && $this->relationLoaded('project')
            ? $user->can('viewAny', [Drawing::class, $this->project])
            : false;

        $data['items'] = $this->relationLoaded('items')
            ? BoqItemResource::collection($this->items)->resolve()
            : [];
        $data['drawings'] = $this->relationLoaded('drawingRevisions')
            ? $this->drawingRevisions
                ->map(fn (DrawingRevision $revision) => BoqPresenter::drawingRevision($revision, $this->project, $canViewDrawings))
                ->values()
                ->all()
            : [];
        $data['source_import'] = $this->relationLoaded('sourceImport') && $this->sourceImport ? [
            'id' => $this->sourceImport->id,
            'original_filename' => $this->sourceImport->original_filename,
            'created_at' => $this->sourceImport->created_at?->toIso8601String(),
            'uploaded_by' => $this->sourceImport->relationLoaded('uploader')
                ? $this->sourceImport->uploader?->only(['id', 'name'])
                : null,
            'download_url' => route('api.v1.imports.file', $this->sourceImport),
            'sheet' => $this->sourceImport->context['sheet_name'] ?? null,
        ] : null;
        $data['import_history'] = $this->importHistory ?? [];

        return $data;
    }
}

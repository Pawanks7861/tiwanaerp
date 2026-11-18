<?php

namespace App\Support\Procurement;

use App\Enums\Boq\BoqStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Procurement\MaterialRequestSourceType;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and snapshots Material Request source (BOQ / Drawing / General).
 */
final class MaterialRequestSource
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   source_type: string,
     *   boq_id: ?int,
     *   boq_item_id: ?int,
     *   drawing_revision_id: ?int,
     *   source_reason: ?string,
     *   source_label_snapshot: string
     * }
     */
    public function resolve(Project $project, array $data): array
    {
        $explicit = array_key_exists('source_type', $data) && filled($data['source_type'] ?? null);
        $type = MaterialRequestSourceType::tryFrom((string) ($data['source_type'] ?? MaterialRequestSourceType::General->value))
            ?? MaterialRequestSourceType::General;

        // Service-level creates that omit source (legacy helpers / mobile until parity) default to General.
        if (! $explicit && $type === MaterialRequestSourceType::General && ! filled($data['source_reason'] ?? null)) {
            $data['source_reason'] = 'General / unplanned material request';
        }

        return match ($type) {
            MaterialRequestSourceType::Boq => $this->fromBoq($project, $data),
            MaterialRequestSourceType::Drawing => $this->fromDrawing($project, $data),
            MaterialRequestSourceType::General => $this->fromGeneral($data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromBoq(Project $project, array $data): array
    {
        $boqId = (int) ($data['boq_id'] ?? 0);
        $boq = Boq::query()
            ->where('project_id', $project->id)
            ->whereKey($boqId)
            ->first();

        if ($boq === null) {
            throw ValidationException::withMessages(['boq_id' => 'Choose a BOQ from this project.']);
        }

        if ($boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            throw ValidationException::withMessages(['boq_id' => 'Choose the current approved BOQ for this project.']);
        }

        $itemId = isset($data['boq_item_id']) && $data['boq_item_id'] !== '' && $data['boq_item_id'] !== null
            ? (int) $data['boq_item_id']
            : null;

        if ($itemId !== null) {
            $item = BoqItem::query()->where('boq_id', $boq->id)->whereKey($itemId)->first();
            if ($item === null) {
                throw ValidationException::withMessages(['boq_item_id' => 'Choose a line from the selected BOQ.']);
            }
        }

        $label = 'BOQ: '.$boq->boq_number.' / R'.$boq->version;

        return [
            'source_type' => MaterialRequestSourceType::Boq->value,
            'boq_id' => $boq->id,
            'boq_item_id' => $itemId,
            'drawing_revision_id' => null,
            'source_reason' => null,
            'source_label_snapshot' => $label,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromDrawing(Project $project, array $data): array
    {
        $revisionId = (int) ($data['drawing_revision_id'] ?? 0);
        $revision = DrawingRevision::query()
            ->with('drawing:id,project_id,drawing_number,title')
            ->whereKey($revisionId)
            ->whereHas('drawing', fn ($q) => $q->where('project_id', $project->id))
            ->first();

        if ($revision === null) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Choose a drawing revision from this project.']);
        }

        if ($revision->status !== DrawingRevisionStatus::Approved) {
            throw ValidationException::withMessages(['drawing_revision_id' => 'Only approved drawing revisions can be used as an MR source.']);
        }

        $drawing = $revision->drawing;
        $revLabel = $revision->revision_code ?? ('R'.$revision->id);
        $label = 'Drawing: '.($drawing?->drawing_number ?? '—').' / '.$revLabel;

        return [
            'source_type' => MaterialRequestSourceType::Drawing->value,
            'boq_id' => null,
            'boq_item_id' => null,
            'drawing_revision_id' => $revision->id,
            'source_reason' => null,
            'source_label_snapshot' => $label,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromGeneral(array $data): array
    {
        $reason = trim((string) ($data['source_reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['source_reason' => 'Provide a reason for a general / unplanned material request.']);
        }

        return [
            'source_type' => MaterialRequestSourceType::General->value,
            'boq_id' => null,
            'boq_item_id' => null,
            'drawing_revision_id' => null,
            'source_reason' => mb_substr($reason, 0, 500),
            'source_label_snapshot' => 'General',
        ];
    }
}

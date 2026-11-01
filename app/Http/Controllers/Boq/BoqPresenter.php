<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;

/**
 * Serialises BOQ data for Inertia/JSON. Cost fields are removed on the server for users without
 * boq.view_costs, so they never reach the browser.
 */
final class BoqPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function header(Boq $boq, bool $withCosts): array
    {
        $data = [
            ...$boq->only(['id', 'boq_number', 'version', 'title', 'is_current', 'parent_boq_id', 'total_client_amount']),
            'status' => $boq->status->value,
            'status_label' => $boq->status->label(),
            'approved_at' => $boq->approved_at?->toIso8601String(),
            'approved_by' => $boq->relationLoaded('approver') ? $boq->approver?->name : null,
            'created_at' => $boq->created_at?->toIso8601String(),
            'updated_at' => $boq->updated_at?->toIso8601String(),
        ];

        if ($withCosts) {
            $data['total_cost_amount'] = $boq->total_cost_amount;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(BoqItem $item, bool $withCosts): array
    {
        $data = $item->only([
            'id', 'boq_section_id', 'line_uid', 'item_code', 'name', 'description', 'hsn_sac', 'unit_id',
            'quantity', 'client_rate', 'client_amount', 'rate_analysis_id', 'sort_order',
        ]);

        if ($withCosts) {
            $data += $item->only(BoqItem::COST_FIELDS);
        }

        return $data;
    }

    /**
     * UI abilities. State is checked here as well as in the policies, because Gate::before grants
     * platform super admins every ability regardless of the document state.
     *
     * @return array<string, bool>
     */
    public static function abilities(User $user, Boq $boq): array
    {
        $editable = $boq->isEditable();
        $revisable = $boq->status === BoqStatus::Approved && $boq->is_current;

        return [
            'update' => $editable && $user->can('update', $boq),
            'submit' => $editable && $user->can('submit', $boq),
            'revise' => $revisable && $user->can('revise', $boq),
            'delete' => $editable && $user->can('delete', $boq),
            'import' => $editable && $user->can('import', $boq),
            'export' => $user->can('export', $boq),
        ];
    }

    /**
     * Approved analysis as offered in the BOQ editor (costs only; caller checks view_costs).
     *
     * @return array<string, mixed>
     */
    public static function analysisOption(RateAnalysis $analysis, array $snapshot): array
    {
        return [
            'id' => $analysis->id,
            'code' => $analysis->code,
            'name' => $analysis->name,
            'unit_id' => $analysis->unit_id,
            'unit_rate' => $analysis->unit_rate,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Drawing revision as shown on BOQ create/detail (approval facts come from the linked revision,
     * not a denormalised copy on the BOQ).
     *
     * @return array<string, mixed>
     */
    public static function drawingRevision(DrawingRevision $revision, Project $project, bool $canViewFiles): array
    {
        $drawing = $revision->drawing;
        $current = $drawing && $drawing->relationLoaded('currentRevision') ? $drawing->currentRevision : null;
        $newer = $current !== null
            && (int) $current->id !== (int) $revision->id
            && $current->status === DrawingRevisionStatus::Approved;

        return [
            'id' => $revision->id,
            'drawing_id' => $drawing?->id,
            'drawing_number' => $drawing?->drawing_number,
            'title' => $drawing?->title,
            'revision_code' => $revision->revision_code,
            'status' => $revision->status->value,
            'status_label' => $revision->status->label(),
            'is_current' => $drawing !== null && (int) $drawing->current_revision_id === (int) $revision->id,
            'approved_at' => $revision->decided_at?->toIso8601String(),
            'approved_by' => $revision->decider?->name,
            'newer_revision' => $newer ? [
                'id' => $current->id,
                'revision_code' => $current->revision_code,
                'approved_at' => $current->decided_at?->toIso8601String(),
                'approved_by' => $current->relationLoaded('decider') ? $current->decider?->name : null,
            ] : null,
            'file_url' => $canViewFiles && $drawing !== null
                ? route('files.show', ['source' => 'drawing_revision', 'id' => $revision->id])
                : null,
            'download_url' => $canViewFiles && $drawing !== null
                ? route('projects.drawings.revisions.download', [$project, $drawing, $revision])
                : null,
        ];
    }
}
